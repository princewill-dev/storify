<?php

namespace App\Http\Controllers\Payment;

use App\Enums\OrderStatus;
use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\PaystackService;
use App\Services\Sms\OrderAlertService;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The legacy Paystack endpoint: own-key deliveries that credit a store's
 * balance.
 *
 * Superseded by {@see PaymentWebhookController}, which settles through
 * {@see PaymentWebhookProcessor} and is what every provider should be pointed
 * at now; this one is still routed because its URL is registered in live
 * Paystack dashboards and removing it would silently stop settlement until
 * somebody remembered to update it there.
 *
 * **It must not be brought under the system processor, and neither must the
 * controller that supersedes it.** An admin selecting one processor stops own
 * keys being *used* for new charges — it does not un-take the money already
 * charged through them, and this is the only thing that can complete those
 * sales. Refusing deliveries here because the platform "no longer uses own
 * keys" would strand every payment made before the switch. What enforcement
 * governs is which credentials start a charge, which is decided in
 * {@see PaymentGatewayResolver} and nowhere else.
 *
 * Its own credit-to-balance model is the reason it is legacy: a managed sale's
 * money is the business's at the provider and must never also land in a balance
 * we hold. Neither path is reachable under enforcement, because neither is how
 * a charge under enforcement is started.
 */
class PaystackWebhookController extends Controller
{
    public function __construct(protected PaystackService $paystackService) {}

    /**
     * Handle incoming Paystack webhook events.
     * Public endpoint — no auth.
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['status' => 'missing signature'], 400);
        }

        $gateway = $this->verifySignature($payload, $signature);

        if (! $gateway) {
            Log::warning('paystack.webhook.unverified_signature', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unverified'], 401);
        }

        $event = json_decode($payload);

        if (! $event || ! isset($event->event)) {
            return response()->json(['status' => 'invalid payload'], 400);
        }

        Log::info('paystack.webhook.received', [
            'event' => $event->event,
            'business_id' => $gateway->business_id,
        ]);

        return match ($event->event) {
            'charge.success' => $this->handleChargeSuccess($event, $gateway),
            default => response()->json(['status' => 'unhandled event']),
        };
    }

    private function handleChargeSuccess($event, $gateway)
    {
        $data = $event->data;
        $reference = $data->reference ?? null;

        if (! $reference) {
            return response()->json(['status' => 'no reference']);
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if (! $transaction) {
            Log::warning('paystack.webhook.transaction_not_found', [
                'reference' => $reference,
                'business_id' => $gateway->business_id,
            ]);

            return response()->json(['status' => 'transaction not found'], 404);
        }

        $transactionStatus = $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : $transaction->status;

        if ($transactionStatus === TransactionStatus::CONFIRMED->value) {
            return response()->json(['status' => 'already processed']);
        }

        try {
            // Double verify with business-specific keys
            $verification = $this->paystackService
                ->usingGateway($gateway)
                ->doubleVerifyPayment($reference);

            if ($verification['success'] && ($verification['data']['status'] ?? '') === 'success') {
                DB::transaction(function () use ($transaction, $data) {
                    $feesKobo = isset($data->fees) ? (int) $data->fees : null;
                    $amountKobo = Naira::koboFromRounded($transaction->amount);
                    $commissionKobo = $transaction->platformFeeKobo();

                    $transaction->update([
                        'status' => TransactionStatus::CONFIRMED->value,
                        'paid_at' => now(),
                        'gateway_reference' => $data->id ?? null,
                        'gateway_response' => $data,
                        'fee_kobo' => $feesKobo,
                        // The business's own Paystack keys: Paystack takes its
                        // fee from the business's account, so the business bears
                        // it, now as before. Stated rather than defaulted so the
                        // row says which rule it is under.
                        'provider_fee_bearer' => ProviderFeeBearer::BUSINESS,
                        // Via the shared rule rather than an inline subtraction.
                        // Zero on this path today — these are the business's own
                        // Paystack keys, with no commission — but a copy of the
                        // expression here is a copy that can drift again.
                        'net_kobo' => Transaction::netKoboFor($amountKobo, $feesKobo, $commissionKobo, ProviderFeeBearer::BUSINESS),
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'webhook_received' => true,
                            'webhook_data' => $data,
                        ]),
                    ]);

                    $order = $transaction->order;

                    if ($order) {
                        $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                        if ($order->isFullyPaid()) {
                            $order->status = OrderStatus::ACCEPTED;
                        }
                        $order->save();

                        $store = $order->store;
                        if ($store) {
                            $amountInKobo = (int) round($transaction->amount * 100);
                            $balanceBefore = (int) $store->balance;
                            $store->creditBalance($amountInKobo);
                            $transaction->update([
                                'balance_updated_at' => now(),
                                'store_balance_before' => $balanceBefore,
                                'store_balance_after' => (int) $store->fresh()->balance,
                            ]);
                        }
                    }
                });

                Log::info('paystack.webhook.payment_verified', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                ]);

                $ledger = app(LedgerPostingService::class);
                $ledger->safe(fn () => $ledger->postPaymentReceived($transaction));

                // Deliver digital products once the order is fully paid
                $order = $transaction->order;
                if ($order && $order->isFullyPaid()) {
                    app(DigitalDeliveryService::class)->deliverSafely($order);
                }

                // This legacy path settles own-key deliveries that the shared
                // processor never sees; it owes the owner the same alert.
                app(OrderAlertService::class)->orderPaid($transaction->fresh());

                return response()->json(['status' => 'success']);
            }

            Log::warning('paystack.webhook.verification_failed', [
                'reference' => $reference,
                'verification' => $verification,
            ]);

            return response()->json(['status' => 'verification failed']);

        } catch (\Throwable $e) {
            Log::error('paystack.webhook.error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Verify HMAC-SHA512 signature against all active business gateway secrets.
     */
    private function verifySignature(string $payload, string $signature): ?object
    {
        $paystackId = PaymentMethod::where('code', 'paystack')->value('id');
        $rows = DB::table('business_payment_method')
            ->where('payment_method_id', $paystackId)->where('is_active', true)->get();

        foreach ($rows as $row) {
            $config = json_decode($row->config, true);
            $secretKey = $config['secret_key'] ?? null;
            if (! $secretKey) {
                continue;
            }

            $computed = hash_hmac('sha512', $payload, $secretKey);

            if (hash_equals($computed, $signature)) {
                $gw = (object) ['public_key' => $config['public_key'] ?? '', 'secret_key' => $secretKey, 'id' => $row->id, 'business_id' => $row->business_id];
                $gw->id = $row->id;
                $gw->business_id = $row->business_id;

                return $gw;
            }
        }

        return null;
    }
}
