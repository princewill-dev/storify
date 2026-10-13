<?php

namespace App\Services\Pos;

use App\Actions\Pos\PosSaleQuote;
use App\Actions\Pos\PricePosSale;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\V1\Pos\PaymentMethodController;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\Naira;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\PaymentGatewayRegistry;
use DomainException;
use Illuminate\Support\Str;

/**
 * Opening a provider's checkout from the till, and asking it what happened.
 *
 * The POS had no relationship with a payment provider at all: `ProcessPosSale`
 * wrote a confirmed transaction and credited the store for every leg, whatever
 * its method. Selecting Paystack therefore produced a receipt, an order and a
 * balance movement for a charge that was never made. This service is the
 * missing half — it asks the provider to start a payment and asks it whether
 * that payment happened.
 *
 * ## The till no longer takes card
 *
 * What is left of the first half is {@see PaymentController}'s two endpoints:
 * a client that opens a provider's checkout still can, and can still poll it.
 * But the till is no longer offered a card method at all — its list is cash and
 * a transfer into the business's own bank account, see
 * {@see PaymentMethodController} — and
 * `/checkout` accepts nothing else, see
 * {@see \App\Http\Requests\Pos\PosCheckoutRequest}. The money for a counter sale
 * is the business's, held in the business's own account, so there is nothing on
 * Storify's side to confirm and nothing to collect against.
 *
 * That is why `assertPaid` became {@see self::assertLegsComplete()}: it used to
 * re-ask the provider at the moment of sale, and now only checks that a leg
 * which needs a reference has one. **The till's word is the record.** The
 * validation list is what keeps that safe — nothing else does.
 *
 * ## Why nothing is written here
 *
 * The obvious design records a pending transaction at initialize and confirms
 * it later, as `Storefront\CheckoutPaymentService` does. It is not done here,
 * deliberately. `transactions` rows without an `order_id` are counted as
 * platform revenue by `Admin\DashboardController` and
 * `Admin\DashboardParityRepository` — neither filters on an order — so an
 * abandoned charge would inflate the superadmin dashboard permanently, and the
 * store-scoped dashboards (which do filter) would never show it.
 *
 * The provider is asked instead of recorded: `initialize` returns a reference,
 * `status` asks the provider what became of it, and `recordPayments` writes the
 * transaction with the provider's own reference on it. No new state, nothing
 * for a report to trip over.
 *
 * Only providers with a hosted checkout are handled here. Manual bank transfer
 * is `offline` — a person confirms it — and keeps its existing behaviour.
 */
final class PosPaymentService
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentGatewayManager $manager,
        private readonly PricePosSale $pricer,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * What this basket costs, and whether it can still be sold.
     *
     * The amount quoted here is the amount charged to the customer's card, and
     * the same `PricePosSale` arithmetic writes the order, so the two cannot
     * disagree. The stock check is a courtesy to the cashier, not the
     * authoritative one — `ProcessPosSale` still de-stocks under a row lock.
     * It exists because the alternative is charging a card for goods this store
     * does not have, and then having to hand the money back.
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     */
    public function quote(Store $store, array $items, ?int $serviceChargeId): PosSaleQuote
    {
        $quote = $this->pricer->execute($store, $items, $serviceChargeId);

        foreach ($quote->lines as $line) {
            if ((int) $line['product']->quantity < $line['quantity']) {
                throw new DomainException("Insufficient stock for {$line['product']->name}.");
            }
        }

        return $quote;
    }

    /**
     * Start a charge for one leg.
     *
     * @param  array{method: string, amount: float|int|string, customer_email?: ?string, customer_name?: ?string}  $leg
     * @return array{mode: string, authorization_url: string, reference: string, access_code: ?string, provider_reference: ?string, amount: float, charge_amount: ?int, charge_currency: ?string, rate: ?float}
     *
     * @throws DomainException when the method is not one this store can charge
     */
    public function initialize(Store $store, User $staff, array $leg, float $amount): array
    {
        $method = (string) $leg['method'];

        $connection = $this->gateways->forStore($store)[$method] ?? null;
        $driver = $this->manager->driver($method);

        if ($connection === null || $driver === null) {
            throw new DomainException('That payment method is not available for this store.');
        }

        if (PaymentGatewayRegistry::checkoutModeFor($method) !== 'redirect') {
            throw new DomainException('That payment method does not have a checkout to open.');
        }

        // The till's amount is in the store's currency; the provider is charged
        // in its own. `chargeMinor` is what the till echoes back on every later
        // call, so every comparison uses the figure the customer was actually
        // quoted rather than a rate re-derived while they were paying.
        $storeCurrency = StoreCurrency::for($store);
        $chargeMinor = $this->rates->convert(Naira::koboFromRounded($amount), $storeCurrency, $driver->currency());

        if ($chargeMinor === null) {
            throw new DomainException(
                "This store charges in {$storeCurrency} and {$method} collects in {$driver->currency()}. "
                ."No {$driver->currency()} exchange rate has been fetched yet, so this payment cannot be priced right now."
            );
        }

        // A sub-cent charge is nothing to charge. Refused here rather than at
        // the provider, which would take a zero payment and leave the till
        // crediting the store for a sale that was never paid for.
        if ($chargeMinor < 1) {
            $smallest = $this->rates->smallestConvertibleMinor($storeCurrency, $driver->currency());
            $minimum = $smallest === null ? null : number_format($smallest / 100, 2);

            throw new DomainException(
                $minimum === null
                    ? "This amount is too small to charge in {$driver->currency()}."
                    : "This amount is too small to charge in {$driver->currency()} — the minimum is {$minimum} {$storeCurrency}."
            );
        }

        // Not `TXN-` and not the storefront's `API_`: a reference we can place
        // at a glance when one turns up in a provider's dashboard.
        $reference = 'POS_'.time().'_'.Str::upper(Str::random(8));

        // A walk-in has no address to send a receipt to, and providers require
        // one, so the platform's own from-address stands in — the same
        // substitution the storefront's checkout makes.
        $email = trim((string) ($leg['customer_email'] ?? ''));
        if ($email === '' || str_contains($email, '@walkin.local')) {
            $email = (string) config('mail.from.address', 'no-reply@storify.test');
        }

        $result = $driver->initialize(
            new PaymentIntent(
                reference: $reference,
                // Never multiply inline: `Naira` owns the naira -> kobo
                // contract, and it produced this before the conversion did.
                amountMinor: $chargeMinor,
                currency: $driver->currency(),
                email: $email,
                callbackUrl: url('/pos/payment-callback'),
                metadata: [
                    'source' => 'pos',
                    'store_id' => $store->id,
                    'staff_id' => $staff->id,
                    'method' => $method,
                ],
                customerName: $leg['customer_name'] ?? null,
            ),
            $connection->credentials,
        );

        if (! $result->success || $result->redirectUrl === null) {
            throw new DomainException($result->message ?? 'The payment could not be started.');
        }

        return [
            'mode' => $result->mode->value,
            'authorization_url' => $result->redirectUrl,
            'reference' => $reference,
            'access_code' => $result->providerId,
            // The provider's own id for this payment, but only when it did not
            // take ours. Most providers echo the reference we handed them and
            // are asked about it by that name; Bitfra generates its own id and
            // is asked about it by that instead. The till hands this straight
            // back with the reference it is polling, so the server is told which
            // name to ask under rather than guessing — passing a Bitfra id to
            // Paystack, or the reverse, is a lookup that can only miss.
            'provider_reference' => $result->ownReference($reference),
            // The store's amount, unchanged and in the store's currency. The
            // till sums these against the sale total, so this must never carry
            // the converted figure.
            'amount' => $amount,
            // What the provider was actually asked to charge, when that differs
            // — the till echoes these back on `status` and on `/checkout`.
            'charge_amount' => $chargeMinor,
            'charge_currency' => $driver->currency(),
            'rate' => $this->rates->divisorFor($storeCurrency, $driver->currency()),
        ];
    }

    /**
     * Ask the provider what became of a reference.
     *
     * Read-only, and writes nothing, so the till can poll it while the customer
     * works through the provider's pages.
     *
     * `$providerReference` is the id the provider knows this payment by, when
     * that is not the reference we issued — see `initialize()`. Asking Bitfra
     * about a `POS_…` reference is a request it cannot answer, so this is what
     * makes a crypto sale completable at the till at all.
     *
     * @return array{paid: bool, pending: bool, message: ?string}
     *
     * @throws DomainException when the method is not one this store can charge
     */
    public function status(
        Store $store,
        string $method,
        string $reference,
        float $amount,
        ?string $providerReference = null,
        ?int $chargeAmountMinor = null,
    ): array {
        $leg = $this->verifyLeg($store, $method, $reference, $amount, $providerReference, $chargeAmountMinor);

        // The provider's own charge is dropped here on purpose. The till is the
        // business's client, not its accountant: on a leg the platform processed,
        // the provider's fee is taken off the money before it reaches us, and it
        // belongs on the transaction row rather than in a cashier's screen. It is
        // read at initialize and written there, not carried through a cashier's
        // status poll.
        return [
            'paid' => $leg['paid'],
            'pending' => $leg['pending'],
            'message' => $leg['message'],
        ];
    }

    /**
     * Ask the provider about one leg.
     *
     * The single place a till leg is verified, and now reached only by
     * {@see self::status()} — the till's own settled-up path no longer asks,
     * because the till no longer takes a method a provider settles. It answers
     * a question and writes nothing, so the till can poll it while the customer
     * works through the provider's pages.
     *
     * The amount is read strictly rather than as a boolean. A provider saying
     * "paid" is not the same as it having been paid the amount asked for —
     * underpayment is a real outcome, and reporting one as settled is a loss,
     * not a rounding detail.
     *
     * @return array{paid: bool, pending: bool, message: ?string}
     *
     * @throws DomainException when the method is not one this store can charge
     */
    private function verifyLeg(
        Store $store,
        string $method,
        string $reference,
        float $amount,
        ?string $providerReference = null,
        ?int $chargeAmountMinor = null,
    ): array {
        $connection = $this->gateways->forStore($store)[$method] ?? null;
        $driver = $this->manager->driver($method);

        if ($connection === null || $driver === null) {
            throw new DomainException('That payment method is not available for this store.');
        }

        $verification = $driver->verify($providerReference ?? $reference, $connection->credentials);

        if (! $verification->isPaid()) {
            return [
                'paid' => false,
                'pending' => $verification->status === VerificationStatus::PENDING,
                'message' => $verification->message,
            ];
        }

        $expected = $this->expectedChargeMinor($store, $driver->currency(), $amount, $chargeAmountMinor);

        if ($expected === null) {
            // The store and the provider charge in different currencies and the
            // till did not send a charge amount, so there is nothing to compare
            // against. That is a till older than this API: it cannot state what
            // the customer was charged in the provider's currency, so it cannot
            // complete the sale either. Said plainly rather than reported as a
            // units mismatch the cashier could never interpret.
            return [
                'paid' => false,
                'pending' => false,
                'message' => "Update the POS app to take this payment method — {$method} charges in {$driver->currency()}.",
            ];
        }

        // Every driver's PAID result carries an amount; one that does not reads
        // as 0 here and is refused, which is the right way round for money we
        // cannot account for.
        if ((int) $verification->amountMinor !== $expected) {
            return [
                'paid' => false,
                'pending' => false,
                'message' => 'The provider recorded a different amount for this payment.',
            ];
        }

        return [
            'paid' => true,
            'pending' => false,
            'message' => null,
        ];
    }

    /**
     * The minor-unit amount the provider should be reporting, or null when the
     * till is too old to say.
     *
     * When the till echoed a charge amount, that is the figure — the same one
     * `initialize()` handed it, never a rate re-derived mid-sale. When it did
     * not, the store and the provider must charge in the same currency for the
     * naira comparison below to mean anything; the six naira providers always
     * are, so nothing about them changes.
     */
    private function expectedChargeMinor(
        Store $store,
        string $providerCurrency,
        float $amount,
        ?int $chargeAmountMinor,
    ): ?int {
        if ($chargeAmountMinor !== null) {
            return $chargeAmountMinor;
        }

        if (strcasecmp(StoreCurrency::for($store), $providerCurrency) !== 0) {
            return null;
        }

        return Naira::koboFromRounded($amount);
    }

    /**
     * Refuse a sale whose legs are not complete enough to be written.
     *
     * This was `assertPaid()`, and it asked the provider about every gateway leg
     * at the moment of sale. It does not any more. The till takes cash and a
     * transfer into the business's own bank account: the money is the business's,
     * it never passes through Storify, and there is nothing here to confirm or to
     * collect against. **A sale the till reports as paid is recorded as paid.**
     *
     * What remains is structural — a leg the catalogue settles by a provider's
     * checkout still has to carry a reference, and `ProcessPosSale` re-checks
     * that itself. Nothing here touches the network, and nothing is written: the
     * only place a row appears is still `ProcessPosSale`, inside its transaction.
     *
     * This method cannot refuse a card leg on its merits, because it no longer
     * looks at any. What keeps a card leg from being written is that
     * {@see PosCheckoutRequest} does not accept one — the guarantee moved out of
     * this class and into validation, and it lives there now.
     *
     * @param  array<int, array<string, mixed>>  $payments  the validated legs
     * @return array<string, int> empty. Kept as a return value, and kept in
     *                            `ProcessPosSale`'s signature, because a leg the
     *                            platform processed would have a provider fee to
     *                            hand over and no path produces one today.
     *
     * @throws DomainException on the first gateway leg with no reference
     */
    public function assertLegsComplete(array $payments): array
    {
        foreach ($payments as $payment) {
            $method = (string) $payment['method'];

            if (! $this->isGatewayMethod($method)) {
                continue;
            }

            $reference = $payment['reference'] ?? $payment['paystack_reference'] ?? null;

            if (empty($reference)) {
                throw new DomainException('A card payment on this sale has not been completed.');
            }
        }

        return [];
    }

    /**
     * Whether this method is settled by a provider's hosted checkout, and so
     * owes the sale a reference proving the money moved.
     *
     * Asked of the catalogue rather than matched against names, so the till,
     * `PaymentMethodController` and `ProcessPosSale` all read one rule.
     */
    public function isGatewayMethod(string $method): bool
    {
        return PaymentGatewayRegistry::has($method)
            && PaymentGatewayRegistry::checkoutModeFor($method) === 'redirect';
    }
}
