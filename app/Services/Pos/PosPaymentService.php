<?php

namespace App\Services\Pos;

use App\Actions\Pos\PosSaleQuote;
use App\Actions\Pos\PricePosSale;
use App\Enums\VerificationStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\Naira;
use App\Support\Payments\PaymentGatewayRegistry;
use DomainException;
use Illuminate\Support\Str;

/**
 * Taking card and wallet money at the till.
 *
 * The POS had no relationship with a payment provider at all: `ProcessPosSale`
 * wrote a confirmed transaction and credited the store for every leg, whatever
 * its method. Selecting Paystack therefore produced a receipt, an order and a
 * balance movement for a charge that was never made. This service is the
 * missing half — it asks the provider to start a payment, asks it whether that
 * payment happened, and gives `CheckoutController` the means to refuse a sale
 * whose gateway legs were not actually paid.
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
 * The provider is the record of truth instead. `initialize` returns a
 * reference, `status` asks the provider what became of it, and `assertPaid`
 * re-asks at the moment of sale, before any row exists. `recordPayments` then
 * writes the transaction exactly as it always did, with the provider's own
 * reference on it. No new state, nothing for a report to trip over.
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
     * @return array{mode: string, authorization_url: string, reference: string, access_code: ?string, provider_reference: ?string, amount: float}
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
                // Never multiply inline: `Naira` owns the naira -> kobo contract.
                amountMinor: Naira::koboFromRounded($amount),
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
            'amount' => $amount,
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

        // A provider saying "paid" is not the same as it having been paid the
        // amount asked for — underpayment is a real outcome, and a sale settled
        // on one is a loss, not a rounding detail. Every driver's PAID result
        // carries an amount; one that does not reads as 0 here and is refused,
        // which is the right way round for money we cannot account for.
        if ((int) $verification->amountMinor !== Naira::koboFromRounded($amount)) {
            return [
                'paid' => false,
                'pending' => false,
                'message' => 'The provider recorded a different amount for this payment.',
            ];
        }

        return ['paid' => true, 'pending' => false, 'message' => null];
    }

    /**
     * Refuse a sale whose gateway legs were not paid.
     *
     * This is the call that makes the till honest. It runs before any order,
     * transaction or stock row exists, so a refusal costs the cashier nothing
     * but the retry.
     *
     * @param  array<int, array<string, mixed>>  $payments  the validated legs
     *
     * @throws DomainException on the first unpaid gateway leg
     */
    public function assertPaid(Store $store, array $payments): void
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

            $status = $this->status(
                $store,
                $method,
                (string) $reference,
                (float) $payment['amount'],
                // Round-tripped from initialize, so a provider that names its
                // own payments is asked by its name and the rest are unchanged.
                isset($payment['provider_reference']) ? (string) $payment['provider_reference'] : null,
            );

            if (! $status['paid']) {
                throw new DomainException($status['message'] ?? 'A card payment on this sale has not been completed.');
            }
        }
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
