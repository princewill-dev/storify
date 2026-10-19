<?php

namespace App\Services\Storefront;

use App\Enums\InitializationMode;
use App\Enums\OrderStatus;
use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Jobs\SettlePayouts;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PaymentPricing;
use App\Services\Sms\OrderAlertService;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\Naira;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\CallbackUrl;
use App\Support\Payments\PaymentWebhookUrl;
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
        private readonly ExchangeRateService $rates,
        private readonly CustodyService $custody,
        private readonly PaymentPricing $pricing,
        private readonly OrderAlertService $alerts,
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

        $reference = $this->pricing->reference('ch');
        $remaining = $order->remainingBalance();

        // Resolve the charge before anything is written. The store's own amount
        // never changes currency — only what the provider is asked for does.
        $storeCurrency = StoreCurrency::for($store);
        $storeAmountMinor = Naira::koboFromRounded($amount);
        $chargeMinor = $this->rates->convert($storeAmountMinor, $storeCurrency, $driver->currency());

        if ($chargeMinor === null) {
            return PaymentInitializationOutcome::gatewayFailed(
                "This store charges in {$storeCurrency} and {$provider} collects in {$driver->currency()}. "
                ."No {$driver->currency()} exchange rate has been fetched yet, so this payment cannot be priced right now."
            );
        }

        // Below one minor unit there is nothing to charge. Refused rather than
        // rounded up to the smallest unit — rounding ₦1 up to $0.01 would
        // overcharge about thirteenfold — and refused visibly, because the
        // alternative is a zero charge that the store is then credited for.
        if ($chargeMinor < 1) {
            $smallest = $this->rates->smallestConvertibleMinor($storeCurrency, $driver->currency());
            $minimum = $smallest === null ? null : number_format($smallest / 100, 2);

            return PaymentInitializationOutcome::gatewayFailed(
                $minimum === null
                    ? "This payment is too small to charge in {$driver->currency()}."
                    : "This payment is too small to charge in {$driver->currency()} — the minimum is {$minimum} {$storeCurrency}."
            );
        }

        // Built once and used twice: written onto the transaction under
        // `metadata.pricing`, and echoed to the customer as `data.pricing`.
        $block = $this->pricing->block($storeCurrency, $storeAmountMinor, $chargeMinor, $driver->currency());

        // Email is not a validated field on this endpoint, so this stays null
        // and the platform from-address is used — as before.
        $email = $order->customer?->email ?: $data['email'] ?? null;

        // Which method took this payment, recorded on the transaction.
        //
        // This is not bookkeeping: `PaymentWebhookProcessor` matches a provider's
        // own payment id against `transactions.gateway_reference` **scoped to
        // this column**, because a provider's ids are small and local and two
        // gateways both have a "12345678". Without it written here, a provider
        // that names its own payments — Bitfra's `payment_id`, Bachs'
        // `checkout_id` — could not have its webhook matched to a storefront
        // order at all, and the sale would settle only if the customer's browser
        // made it back to the callback. The admin reports join on this column
        // too, and were counting these payments as belonging to no method.
        $paymentMethodId = PaymentMethod::query()->where('code', $provider)->value('id');

        if (! $email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        // The platform's own arrangement, when it has one with this business.
        //
        // Recorded on the transaction because it is the one fact the settlement
        // path cannot re-derive later: the charge is made on Storify's
        // credentials, so whether the business is owed a wallet credit is decided
        // by the arrangement in force *when the customer paid*, not by whatever
        // the custody column happens to say days later when the webhook lands.
        // The commission is fixed here for the same reason — at the rate in force
        // today, not at whatever rate is configured when the payment settles.
        //
        // Nothing per-account is written down any more, because there is nothing
        // per-account to write: the charge names no business account at the
        // provider at all, so the platform's own credentials are the whole story
        // of where the money went and where a refund returns to.
        $managed = $connection->isManaged();
        $commissionKobo = $managed ? PlatformCommission::fromSettings()->on($chargeMinor) : null;

        DB::beginTransaction();

        try {
            $transaction = Transaction::create([
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $amount,
                // The store's currency, because that is the currency `amount`
                // is in. This previously held the driver's currency, which
                // labelled a naira figure "USD" for Bitfra.
                'currency' => $storeCurrency,
                'status' => TransactionStatus::PENDING,
                // The commission as a column, alongside the metadata key below.
                // Both are written: the column is what queries, totals and the
                // backfill read; the metadata key is the record of what was
                // charged, kept so a row written before this column existed
                // still reports the right thing.
                'platform_fee_kobo' => $commissionKobo,
                'metadata' => array_merge([
                    'order_number' => $order->order_number,
                    'is_partial' => $amount < $remaining,
                    'source' => 'storefront_api',
                    'provider' => $provider,
                    'payment_mode' => $connection->paymentMode(),
                    'platform_fee_kobo' => $commissionKobo,
                ], $this->pricing->metadata($block)),
            ]);

            $result = $driver->initialize(
                new PaymentIntent(
                    reference: $reference,
                    // The driver's contract is that this is in *its* currency,
                    // so the store amount is converted first. When the two
                    // currencies match — six providers of seven — this is the
                    // unchanged (int) round($amount * 100).
                    amountMinor: $chargeMinor,
                    currency: $driver->currency(),
                    email: (string) $email,
                    // The return page is told which payment it is receiving,
                    // because a provider that decorates the URL decorates it
                    // with its own name — Bachs appends `checkout_id`, Paystack
                    // its own `reference` — and only the latter is one this
                    // application can look a payment up by.
                    callbackUrl: CallbackUrl::withReference(
                        (string) ($data['callback_url'] ?? url('/')),
                        $reference,
                    ),
                    metadata: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'transaction_id' => $transaction->id,
                        // Manual transfer has no API to ask where the money
                        // goes, so the store's own accounts travel with the
                        // intent and the driver formats them as instructions.
                        'bank_accounts' => $this->bankAccountsFor($store),
                    ],
                    // Derived from the store, never from the request: the
                    // client-supplied `callback_url` above is where the
                    // customer's browser returns to, and letting it double as
                    // the alert destination would hand a stranger control of
                    // where payment notifications are posted.
                    webhookUrl: PaymentWebhookUrl::forStore($provider, $store),
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
                'metadata' => array_merge($transaction->metadata ?? [], [
                    // The name to ask the provider about this payment under, when
                    // it did not adopt the reference we issued — null for most
                    // providers, and Bitfra's own `payment_id` for that one. Kept
                    // so the customer's return to `verify()` can address the
                    // provider correctly instead of waiting on the webhook.
                    'provider_reference' => $result->ownReference($reference),
                ]),
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
                pricing: $block,
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

        // Asked under whichever name the provider knows this payment by. For
        // every provider but Bitfra that is the reference in the request; Bitfra
        // issued its own id at initialize and has no lookup by ours, so the
        // stored one is used instead. A provider that never accepted our
        // reference cannot be verified with it, and the only place that id was
        // ever written down is this row.
        $providerReference = $transaction->metadata['provider_reference'] ?? null;

        // Already settled. Crediting again would double the store's balance and
        // the order's `amount_paid`, and this is reachable in the ordinary
        // course of things: a webhook settles the payment and then the
        // customer's browser lands on the verify callback, or they refresh the
        // success page. `PaymentWebhookProcessor` has always guarded on the same
        // status; this path did not.
        if ($transaction->status === TransactionStatus::CONFIRMED) {
            return PaymentVerificationOutcome::verified($transaction->order()->firstOrFail());
        }

        $verification = $driver->verify($providerReference ?? $reference, $connection->credentials);
        $order = $transaction->order;

        if ($verification->isPaid()) {
            // A provider that reports paid for less than it was asked to charge
            // must not settle the order for the full store amount. This is the
            // floor that converting a naira store into dollars makes reachable
            // — while the currency gate confined Bitfra to USD stores it was
            // unreachable for them, and every other provider settles in the
            // store's own currency so the two figures coincide.
            if ($this->pricing->isUnderpaid($transaction, $verification)) {
                return PaymentVerificationOutcome::failed(
                    'The amount received is less than the amount charged for this payment.'
                );
            }

            // Whether this sale was the platform's is a property of how it was
            // charged, read before anything is written. The connection can have
            // been revoked or re-pointed since, and the money belongs to
            // whichever arrangement was in force when the customer paid.
            $managed = $transaction->isPlatformManaged();
            $commissionKobo = $transaction->platformFeeKobo();

            // And whether Storify collected it, read once here for the same
            // reason: the books and the wallet have to agree about which
            // arrangement was in force when the customer paid, and this method
            // can be reached by a browser refresh minutes after the webhook
            // settled the same sale — the wallet's own claim is what makes the
            // second arrival a no-op rather than a second credit.
            //
            // Read from the row rather than from the business's switch, for the
            // reason spelled out at the same line in
            // {@see PaymentWebhookProcessor::settle()}: `custody_enabled` decides
            // how a business is charged from now on, and this decides where money
            // charged up to two days ago belongs. The two paths must agree, and
            // this is the one fact they can agree on — the row's own record of how
            // it was charged.
            $custody = $managed;

            // Who paid the provider's own charge. Must agree with the webhook's
            // reading of the same sale, for the reason the `$custody` comment
            // above gives: only one of the two paths settles a given sale, but
            // either may be the one that does.
            $bearer = $managed ? ProviderFeeBearer::PLATFORM : ProviderFeeBearer::BUSINESS;

            DB::transaction(function () use ($transaction, $order, $verification, $managed, $commissionKobo, $bearer) {
                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'gateway_reference' => $verification->providerId,
                    'gateway_response' => $verification->raw,
                    'paid_at' => now(),
                    'fee_kobo' => $verification->feesMinor,
                    'provider_fee_bearer' => $bearer,
                    // This path never wrote a net at all, so a payment settled
                    // by the customer's own return trip rather than by webhook
                    // showed an empty Net forever. Same rule as the webhook's:
                    // on the business's own keys both fees come off and nothing
                    // is written when the provider's is unknown; on ours only the
                    // commission does, and it is known before the charge is.
                    'platform_fee_kobo' => $managed ? $commissionKobo : null,
                    'net_kobo' => Transaction::netKoboFor(
                        Naira::koboFromRounded($transaction->amount),
                        $verification->feesMinor,
                        $commissionKobo,
                        $bearer,
                    ),
                ]);

                $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                    $order->status = OrderStatus::ACCEPTED;
                }

                $order->save();

                $storeModel = $order->store;

                // Not for a managed sale: that money is in the business's own
                // account at the provider, already net of its fees, and a
                // balance of ours credited on top of it would be us holding
                // money that is not ours to hold.
                if ($storeModel && ! $managed) {
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

            // The payment is committed; the customer is on the return leg
            // rather than waiting on a webhook, so this is often the first
            // settlement path to reach the sale. The dedupe key makes the
            // webhook's later arrival a no-op.
            $this->alerts->orderPaid($transaction->fresh());

            if ($managed) {
                $this->ledger->safe(fn () => $this->ledger->postManagedPaymentReceived(
                    $transaction,
                    $commissionKobo,
                    custody: $custody,
                ));

                // Under custody the commission is already credited to 4020 by
                // the collection entry that `recordCollectedSale()` posts, so
                // recognising it a second time here would report income twice.
                if (! $custody) {
                    $this->ledger->safe(fn () => $this->ledger->postPlatformCommissionEarned($transaction, $commissionKobo));
                }
            } else {
                $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, null));
            }

            if ($custody) {
                // The driver that verified the payment is the provider that
                // collected it, which is what its settlement window is keyed on.
                $this->custody->recordCollectedSale($transaction, $driver->code());

                // Then send it, off this request. The return leg is usually the
                // first of the two settlement paths to reach a sale — the customer
                // is back at the confirmation page before the webhook is delivered
                // — so without this the normal case would still wait for the
                // provider's own callback.
                //
                // On the queue rather than inline now, so the customer's
                // confirmation page does not wait on a provider round trip. The job
                // reads the restrictions (a system, business or store hold,
                // `auto_payout`, the destination) before it calls the provider, and
                // it re-attempts on `custody.retry_backoff_minutes` before it gives
                // up and tells the admins. A customer's confirmation can never be
                // failed by a slow provider: the sale is credited and payable
                // whatever the job does next.
                SettlePayouts::forSale($transaction);
            }

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
}
