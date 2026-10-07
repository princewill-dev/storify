<?php

namespace App\Services\Storefront;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\PaystackService;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The gateway money movement behind storefront checkout.
 *
 * Initialization and verification own their transaction boundaries, the
 * gateway round-trips, the per-store key selection, the store balance credit,
 * the ledger posting and the digital delivery — each at the exact point the
 * controller ran it. The controller keeps the state guards (the 409 "already
 * fully paid", the 422 "invalid amount") and the message strings and status
 * codes the outcomes map onto.
 *
 * Initialization deliberately uses the manual begin/rollback/commit the
 * controller carried: the pending transaction row is written before the
 * gateway call and rolled back when the gateway refuses, so a failed
 * initialization leaves no transaction behind.
 */
final class CheckoutPaymentService
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly CheckoutRepository $repository,
        private readonly LedgerPostingService $ledger,
        private readonly DigitalDeliveryService $digital,
    ) {}

    /**
     * Initialize a Paystack charge for an order and persist its pending
     * transaction.
     *
     * @param  array<string, mixed>  $data  the validated payload
     */
    public function initialize(Store $store, Order $order, float $amount, array $data): PaymentInitializationOutcome
    {
        $this->useStorePaystackKeys($store);

        $reference = $this->paystack->generateReference('API');
        $remaining = $order->remainingBalance();

        // Email is not a validated field on this endpoint, so this stays null
        // and the platform from-address is used — as before.
        $email = $order->customer?->email ?: $data['email'] ?? null;

        if (! $email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        DB::beginTransaction();

        try {
            $transaction = Transaction::create([
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'amount' => $amount,
                'currency' => 'NGN',
                'status' => TransactionStatus::PENDING,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'is_partial' => $amount < $remaining,
                    'source' => 'storefront_api',
                ],
            ]);

            $result = $this->paystack->initializePayment([
                'email' => $email,
                // The gateway takes kobo; the inline expression was
                // (int) round($amount * 100), Naira::koboFromRounded's exact
                // contract.
                'amount' => Naira::koboFromRounded($amount),
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => $data['callback_url'] ?? url('/'),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'transaction_id' => $transaction->id,
                ],
            ]);

            if (! ($result['success'] ?? false)) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed($result['message'] ?? null);
            }

            $transaction->update(['gateway_response' => $result['data']]);
            DB::commit();

            return PaymentInitializationOutcome::initialized(
                $result['data']['authorization_url'],
                $reference,
                $amount,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('storefront_api.paystack_initialize_failed', ['error' => $e->getMessage(), 'order' => $order->order_number]);

            return PaymentInitializationOutcome::failed();
        }
    }

    /**
     * Double-verify a payment with the gateway, then settle it: the
     * transaction is confirmed, the order's amount paid and status move, the
     * store balance is credited and the balance columns are recorded, the
     * ledger posts and digital delivery runs if the order is now fully paid —
     * the verification's exact sequence.
     *
     * The caller passes the reference the request carried; the gateway is
     * called with that string, not with whatever the row's stored reference
     * happens to be, exactly as the controller did.
     */
    public function verify(Store $store, Transaction $transaction, string $reference): PaymentVerificationOutcome
    {
        $this->useStorePaystackKeys($store);

        $verification = $this->paystack->doubleVerifyPayment($reference);

        $order = $transaction->order;

        if (($verification['success'] ?? false) && strtolower((string) ($verification['data']['status'] ?? '')) === 'success') {
            DB::transaction(function () use ($transaction, $order, $verification) {
                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'gateway_reference' => $verification['data']['id'] ?? null,
                    'gateway_response' => $verification['data'],
                    'paid_at' => now(),
                ]);

                $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                    $order->status = OrderStatus::ACCEPTED;
                }

                $order->save();

                $storeModel = $order->store;

                if ($storeModel) {
                    // The inline expression was
                    // (int) round((float) $transaction->amount * 100),
                    // Naira::koboFromRounded's exact contract.
                    $amountKobo = Naira::koboFromRounded($transaction->amount);
                    $before = (int) $storeModel->balance;
                    $storeModel->creditBalance($amountKobo);
                    $transaction->update([
                        'balance_updated_at' => now(),
                        'store_balance_before' => $before,
                        'store_balance_after' => (int) $storeModel->fresh()->balance,
                    ]);
                }
            });

            $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, null));

            if ($order->isFullyPaid()) {
                $this->digital->deliverSafely($order);
            }

            $order->refresh();

            return PaymentVerificationOutcome::verified($order);
        }

        return PaymentVerificationOutcome::failed($verification['message'] ?? null);
    }

    /**
     * Swap the Paystack client onto the store's own credentials when it has
     * them; the platform keys stay in place otherwise. The key blob is read
     * from the gateway row's pivot and decoded when it arrives as JSON text.
     */
    private function useStorePaystackKeys(Store $store): void
    {
        $gateway = $this->repository->paystackGatewayFor($store);
        $keys = $gateway?->pivot?->api_keys ?? [];

        if (is_string($keys)) {
            $keys = json_decode($keys, true) ?: [];
        }

        if (! empty($keys['secret_key'])) {
            $this->paystack->usingGateway((object) [
                'secret_key' => $keys['secret_key'],
                'public_key' => $keys['public_key'] ?? '',
            ]);
        }
    }
}
