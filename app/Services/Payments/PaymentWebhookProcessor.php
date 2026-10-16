<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookScope;
use App\Support\Money\Naira;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly CustodyService $custody,
        private readonly InstantPayoutService $instant,
    ) {}

    /**
     * @param  WebhookScope|null  $scope  the connection the URL named, when it named one
     * @return array{status: string, http: int}
     */
    public function settle(
        WebhookEvent $event,
        PaymentGateway $driver,
        GatewayCredentials $credentials,
        ?WebhookScope $scope = null,
    ): array {
        $reference = $event->reference;

        if ($reference === null) {
            return ['status' => 'no reference', 'http' => Response::HTTP_OK];
        }

        $transaction = $this->findTransaction($reference, $driver, $scope);

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

            // Refuse a confirmation for less than this payment was asked to
            // charge. Only reachable when a converted charge was recorded —
            // `chargeAmountMinor()` is null for every naira transaction — but it
            // is the safety net for a USD-quoted charge on an NGN store, where a
            // provider reporting the store amount in cents would otherwise
            // settle an order it was never paid for.
            if ($this->isUnderpaid($transaction, $verification)) {
                return ['status' => 'underpaid', 'http' => Response::HTTP_OK];
            }

            $feesMinor = $verification->feesMinor ?? $event->feesMinor;

            // Read before the write below: whether this sale was the platform's
            // is a property of how it was charged, and must not be re-derived
            // from a connection that may have changed since.
            $managed = $transaction->isPlatformManaged();
            $commissionKobo = $transaction->platformFeeKobo();

            // And whether Storify collected it, or the business's own account at
            // the provider did. Decided once, here, and handed to both the books
            // and the wallet below — the two must act on the same reading, and
            // re-deriving it after this point would let a flip in between put
            // money in a wallet whose asset account never received it.
            //
            // **Which is why it is the row's own `payment_mode` and not the
            // business's switch.** Those two used to be and-ed together, and the
            // second one is the wrong fact: `custody_enabled` says how a business
            // is *charged* from now on, while this runs when a charge made up to
            // two days ago settles. A business rolled back inside that window — the
            // one moment `custody:disable` is most likely to be run — had its sale
            // treated as a sale on its own keys, so it was mirrored into
            // `stores.balance` and never credited to a wallet, while the money was
            // sitting in Storify's balance. The transaction already records which
            // arrangement was in force when the customer paid, which is the only
            // question being asked here.
            $custody = $managed;

            // Who paid the provider's own charge, written where the sale is
            // settled so no later reader has to re-derive it from a connection
            // that may have been re-pointed since. For a sale we processed on the
            // business's behalf that is us: the provider takes its fee out of
            // our balance, so the fee must not come off the business's net.
            $bearer = $managed ? ProviderFeeBearer::PLATFORM : ProviderFeeBearer::BUSINESS;

            DB::transaction(function () use ($transaction, $verification, $feesMinor, $managed, $commissionKobo, $bearer) {
                $amountMinor = Naira::koboFromRounded($transaction->amount);

                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'paid_at' => now(),
                    'gateway_reference' => $verification->providerId,
                    'gateway_response' => $verification->raw,
                    'fee_kobo' => $feesMinor,
                    'provider_fee_bearer' => $bearer,
                    // What comes off the business's net depends on that bearer.
                    // On its own keys, both fees do — the provider's charge and
                    // our commission, both taken before the money landed.
                    // On ours, only the commission does: the provider's charge is
                    // our cost, and subtracting it here is what made a business
                    // pay for a provider it never chose.
                    // `platform_fee_kobo` is the column where it exists and the
                    // charge-time metadata key where it does not, so settling a
                    // sale charged before the column existed writes it into the
                    // column on the way past rather than waiting for the backfill.
                    'platform_fee_kobo' => $managed ? $commissionKobo : null,
                    'net_kobo' => Transaction::netKoboFor($amountMinor, $feesMinor, $commissionKobo, $bearer),
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

                    // The store's own wallet, for a sale charged through the
                    // store's or business's own gateway. **Not** for a managed
                    // sale: that money is in the business's own account at the
                    // provider, already net of its fees, and crediting a balance
                    // we hold on top of it would be holding money that is not
                    // ours — the exact thing the managed model exists to avoid.
                    if ($store && ! $managed) {
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

            if ($managed) {
                // The business's side — net asset, our commission as its expense
                // — and ours, the commission we were paid. Two entries, two sets
                // of books, and neither touches a balance we hold. The provider's
                // own fee is ours now, and the method reads it off the row rather
                // than taking it as an argument, so no caller can put it back on
                // a business's books by passing the wrong number.
                $this->ledger->safe(fn () => $this->ledger->postManagedPaymentReceived(
                    $transaction,
                    $commissionKobo,
                    custody: $custody,
                ));

                // Under custody the commission is already inside the collection
                // entry, credited to 4020 against the same clearing balance, so
                // posting it here as well would double our own income. The
                // split-at-source entry exists because the provider took the
                // commission off before the money reached us and it had to be
                // recognised separately; when we collect the whole charge, the
                // one entry already says everything.
                if (! $custody) {
                    $this->ledger->safe(fn () => $this->ledger->postPlatformCommissionEarned($transaction, $commissionKobo));
                }
            } else {
                $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction));
            }

            if ($custody) {
                // Owed to the business, but not necessarily settled to us yet —
                // the wallet puts it in `pending` with a settlement date, and
                // the promote job is what makes it payable. The ledger's own
                // account for the same money is 2300 on our books and 1040 on
                // theirs, both posted above.
                $this->custody->recordCollectedSale($transaction, $driver->code());

                // Then pay it out, now, rather than at the next tick of the run.
                //
                // **Which of the two settlement paths pays depends only on which
                // heard about the payment first, and for one sale only one ever
                // gets here.** A delivery that arrives after the browser's return
                // leg finds the row already `CONFIRMED` and returns at the guard
                // far above; when it arrives first, the return leg's own later
                // visit finds the same thing and returns the same way. So the
                // payout is made once, by whichever path was first, and the
                // return leg makes this call in the same position for the same
                // reason — see {@see CheckoutPaymentService::verify()}.
                //
                // Not gated on the return value of the line above: that asks
                // whether *this call* did the crediting, while what decides
                // whether there is money to send is whether the sale is credited
                // at all. {@see InstantPayoutService::afterCollection()} reads
                // that from `settle_at`, the stamp both paths write and only one
                // can take.
                //
                // Never throws: a provider that cannot be reached here leaves the
                // sale payable and the run pays it on its next pass, which is a
                // better outcome than failing a delivery and having the payment
                // redelivered for a business we can still pay.
                $this->instant->afterCollection($transaction);
            }

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

    /**
     * Whether the provider reports less than the recorded charge.
     *
     * `<` and not `!=`: overpayment through FX movement between checkout and
     * settlement is legitimate and must still settle.
     */
    private function isUnderpaid(Transaction $transaction, VerificationResult $verification): bool
    {
        $expected = $transaction->chargeAmountMinor();

        if ($expected === null || $verification->amountMinor >= $expected) {
            return false;
        }

        Log::warning('payments.settlement.underpaid', [
            'reference' => $transaction->reference,
            'expected_minor' => $expected,
            'reported_minor' => $verification->amountMinor,
            'charge_currency' => $transaction->metadata['pricing']['charge_currency'] ?? null,
        ]);

        return true;
    }

    private function status(Transaction $transaction): string
    {
        return $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : (string) $transaction->status;
    }

    /**
     * The transaction this webhook is about, or null.
     *
     * Two lookups, in order: the reference we issued, then the provider's own id
     * which we recorded at initialize. Not every provider lets us supply a
     * reference — Bitfra generates its own payment id and quotes that — so the
     * second lookup is what makes those providers settle at all.
     */
    private function findTransaction(string $reference, PaymentGateway $driver, ?WebhookScope $scope): ?Transaction
    {
        $transaction = $this->scopeQuery(Transaction::where('reference', $reference), $scope)->first();

        if ($transaction !== null) {
            return $transaction;
        }

        // Scoped to this provider's method id on purpose: provider ids are small
        // and local, and two gateways can easily both have a payment "12345678".
        // Matching one provider's webhook onto another provider's transaction
        // would settle the wrong order.
        $methodId = $driver->code() === ''
            ? null
            : DB::table('payment_methods')
                ->where('code', $driver->code())
                ->value('id');

        if ($methodId === null) {
            return null;
        }

        return $this->scopeQuery(
            Transaction::where('gateway_reference', $reference)
                ->where('payment_method_id', $methodId),
            $scope,
        )->first();
    }

    /**
     * Confine the lookup to the tenant the webhook URL named.
     *
     * Without a scope this changes nothing — that is the provider-only URL, and
     * it keeps the behaviour it has always had. With one, a signature that
     * verified against this connection can no longer settle a transaction
     * belonging to a different business. The narrower half of that is real
     * today: the provider-id fallback above is scoped only to the method, and a
     * small local id like "12345678" is exactly the sort of thing two businesses
     * both have.
     *
     * ## Why the store is only applied when it owns its keys
     *
     * A store with its own provider account is notified about that store and
     * nothing else, so a payment it reports cannot belong to a sibling store —
     * and scoping to it is safe. A store charging through the business's shared
     * account is not: the provider posts about every store through that one
     * account, so a store-level filter would quietly drop most of the business's
     * payments. There, the reach stays business-wide.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function scopeQuery($query, ?WebhookScope $scope)
    {
        if ($scope === null) {
            return $query;
        }

        $query->where('transactions.business_id', $scope->businessId);

        if ($scope->storeId !== null && $scope->storeOwnsKeys) {
            $query->whereHas('order', fn ($order) => $order->where('store_id', $scope->storeId));
        }

        return $query;
    }
}
