<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\WebhookEvent;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settles a payment once a webhook says it happened.
 *
 * Extracted from the Paystack webhook controller so every provider reuses one
 * settlement path. The provider-specific half — verifying a signature and
 * naming the event — stays in each driver's `parseWebhook()`; everything that
 * moves money, credits a balance, posts to the ledger or delivers a download is
 * here, so a new provider cannot get it subtly wrong.
 *
 * Idempotency is the load-bearing part. **Both** a webhook and the client's own
 * verify callback can settle the same payment, and providers retry failed
 * webhooks up to three times. The guard is the transaction's own status: a
 * confirmed transaction is never settled twice, so a retry is a no-op rather
 * than a second balance credit.
 */
final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly DigitalDeliveryService $digital,
    ) {}

    /**
     * @return array{status: string, http: int}
     */
    public function settle(WebhookEvent $event, PaymentGateway $driver, GatewayCredentials $credentials): array
    {
        $reference = $event->reference;

        if ($reference === null) {
            return ['status' => 'no reference', 'http' => Response::HTTP_OK];
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if ($transaction === null) {
            // Not every provider lets us supply a reference. Bitfra generates
            // its own payment id and quotes that in the webhook, so fall back
            // to the id we recorded at initialize.
            //
            // Scoped to this provider's method id on purpose: provider ids are
            // small and local, and two gateways can easily both have a payment
            // "12345678". Matching one provider's webhook onto another
            // provider's transaction would settle the wrong order.
            $methodId = $driver->code() === ''
                ? null
                : DB::table('payment_methods')
                    ->where('code', $driver->code())
                    ->value('id');

            if ($methodId !== null) {
                $transaction = Transaction::where('gateway_reference', $reference)
                    ->where('payment_method_id', $methodId)
                    ->first();
            }
        }

        if ($transaction === null) {
            // Answered 200 rather than 404 on purpose: the transaction may
            // simply not have been written yet, and returning an error makes
            // the provider retry a webhook that has nothing wrong with it.
            Log::warning('payments.webhook.transaction_not_found', ['reference' => $reference]);

            return ['status' => 'transaction not found', 'http' => Response::HTTP_OK];
        }

        if ($this->status($transaction) === TransactionStatus::CONFIRMED->value) {
            return ['status' => 'already processed', 'http' => Response::HTTP_OK];
        }

        try {
            // Re-verify with the provider rather than trusting the webhook body.
            // A webhook is a notification, not proof; the signature proves it
            // came from the provider, but only an API call proves the payment
            // actually succeeded and for how much.
            $verification = $driver->verify($reference, $credentials);

            if (! $verification->isPaid()) {
                Log::warning('payments.webhook.verification_failed', [
                    'reference' => $reference,
                    'provider' => $driver->code(),
                ]);

                return ['status' => 'verification failed', 'http' => Response::HTTP_OK];
            }

            $feesMinor = $verification->feesMinor ?? $event->feesMinor;

            DB::transaction(function () use ($transaction, $verification, $feesMinor) {
                $amountMinor = Naira::koboFromRounded($transaction->amount);

                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'paid_at' => now(),
                    'gateway_reference' => $verification->providerId,
                    'gateway_response' => $verification->raw,
                    'fee_kobo' => $feesMinor,
                    'net_kobo' => $feesMinor !== null ? max(0, $amountMinor - $feesMinor) : null,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'webhook_received' => true,
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
                        $balanceBefore = (int) $store->balance;
                        $store->creditBalance($amountMinor);
                        $transaction->update([
                            'balance_updated_at' => now(),
                            'store_balance_before' => $balanceBefore,
                            'store_balance_after' => (int) $store->fresh()->balance,
                        ]);
                    }
                }
            });

            $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction));

            $order = $transaction->order;

            if ($order && $order->isFullyPaid()) {
                $this->digital->deliverSafely($order);
            }

            Log::info('payments.webhook.settled', [
                'reference' => $reference,
                'provider' => $driver->code(),
                'transaction_id' => $transaction->id,
            ]);

            return ['status' => 'success', 'http' => Response::HTTP_OK];
        } catch (\Throwable $e) {
            Log::error('payments.webhook.error', [
                'reference' => $reference,
                'provider' => $driver->code(),
                'error' => $e->getMessage(),
            ]);

            // 500 so the provider retries — this one genuinely failed.
            return ['status' => 'error', 'http' => Response::HTTP_INTERNAL_SERVER_ERROR];
        }
    }

    private function status(Transaction $transaction): string
    {
        return $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : (string) $transaction->status;
    }
}
