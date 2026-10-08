<?php

namespace App\Services\Storefront;

use App\Enums\InitializationMode;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The money movement behind storefront checkout, for every provider.
 *
 * This was Paystack-shaped: it swapped the shared `PaystackService` onto the
 * store's keys and called it directly, so a second provider could not be
 * charged through at all. It now resolves the store's connection, asks the
 * provider's driver, and works the same way for a hosted checkout and for a
 * bank transfer that has no provider behind it.
 *
 * Initialization keeps the manual begin/rollback/commit the controller
 * carried: the pending transaction row is written before the gateway call and
 * rolled back when the gateway refuses, so a failed initialization leaves no
 * transaction behind.
 */
final class CheckoutPaymentService
{
    public function __construct(
        private readonly CheckoutRepository $repository,
        private readonly LedgerPostingService $ledger,
        private readonly DigitalDeliveryService $digital,
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentGatewayManager $manager,
    ) {}

    /**
     * Start a charge for an order through one provider.
     *
     * @param  array<string, mixed>  $data  the validated payload
     */
    public function initialize(
        Store $store,
        Order $order,
        float $amount,
        array $data,
        string $provider = 'paystack',
    ): PaymentInitializationOutcome {
        $connection = $this->gateways->forStore($store)[$provider] ?? null;
        $driver = $this->manager->driver($provider);

        if ($connection === null || $driver === null) {
            return PaymentInitializationOutcome::gatewayFailed(
                'That payment method is not available for this store.'
            );
        }

        $reference = $this->generateReference();
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
                'currency' => $driver->currency(),
                'status' => TransactionStatus::PENDING,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'is_partial' => $amount < $remaining,
                    'source' => 'storefront_api',
                    'provider' => $provider,
                ],
            ]);

            $result = $driver->initialize(
                new PaymentIntent(
                    reference: $reference,
                    // The gateway takes the currency's minor unit; the inline
                    // expression was (int) round($amount * 100), which is
                    // Naira::koboFromRounded's exact contract.
                    amountMinor: Naira::koboFromRounded($amount),
                    currency: $driver->currency(),
                    email: (string) $email,
                    callbackUrl: (string) ($data['callback_url'] ?? url('/')),
                    metadata: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id,
                        // Manual transfer has no API to ask where the money
                        // goes, so the store's own accounts travel with the
                        // intent and the driver formats them as instructions.
                        'bank_accounts' => $this->bankAccountsFor($store),
                    ],
                ),
                $connection->credentials,
            );

            if (! $result->success) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed($result->message);
            }

            // Record the provider's own id the moment we have it. Some
            // providers identify a payment by their id rather than by any
            // reference we supply, so this is the only thing their webhook can
            // be matched against — and it is not knowable after the fact.
            $transaction->update([
                'gateway_response' => $result->raw,
                'gateway_reference' => $result->providerId ?? $result->providerReference,
            ]);
            DB::commit();

            if ($result->mode === InitializationMode::OFFLINE) {
                return PaymentInitializationOutcome::offline(
                    $reference,
                    $amount,
                    $result->instructions,
                    $result->message,
                );
            }

            return PaymentInitializationOutcome::initialized(
                $result->redirectUrl,
                $reference,
                $amount,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('storefront.payment_initialize_failed', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'order' => $order->order_number,
            ]);

            return PaymentInitializationOutcome::failed();
        }
    }

    /**
     * Verify a payment with its provider, then settle it: the transaction is
     * confirmed, the order's amount paid and status move, the store balance is
     * credited and the balance columns are recorded, the ledger posts and
     * digital delivery runs if the order is now fully paid.
     *
     * The caller passes the reference the request carried; the provider is
     * called with that string, not with whatever the row's stored reference
     * happens to be, exactly as the controller did.
     */
    public function verify(
        Store $store,
        Transaction $transaction,
        string $reference,
        string $provider = 'paystack',
    ): PaymentVerificationOutcome {
        $connection = $this->gateways->forStore($store)[$provider] ?? null;
        $driver = $this->manager->driver($provider);

        if ($connection === null || $driver === null) {
            return PaymentVerificationOutcome::failed('That payment method is not available for this store.');
        }

        $verification = $driver->verify($reference, $connection->credentials);
        $order = $transaction->order;

        if ($verification->isPaid()) {
            DB::transaction(function () use ($transaction, $order, $verification) {
                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'gateway_reference' => $verification->providerId,
                    'gateway_response' => $verification->raw,
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

        return PaymentVerificationOutcome::failed($verification->message);
    }

    /**
     * The store's verified bank accounts, for a provider that settles offline.
     *
     * @return array<int, array<string, string>>
     */
    private function bankAccountsFor(Store $store): array
    {
        return $this->repository->verifiedBankAccountsFor($store)
            ->map(fn ($bank): array => [
                'bank_name' => (string) $bank->bank_name,
                'account_number' => (string) $bank->account_number,
                'account_name' => (string) $bank->account_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Same shape `PaystackService::generateReference('API')` produced, kept
     * byte-for-byte so references written before this change stay
     * indistinguishable from the ones written after it.
     */
    private function generateReference(): string
    {
        return 'API_'.time().'_'.strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
