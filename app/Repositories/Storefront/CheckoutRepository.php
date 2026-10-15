<?php

namespace App\Repositories\Storefront;

use App\Models\Customer;
use App\Models\DigitalDownload;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads behind the storefront checkout: the payment options a storefront
 * offers, the order and transaction lookups every payment endpoint is scoped
 * by, the store's gateway credential row and an order's download rows.
 *
 * The order and transaction lookups carry this surface's tenancy boundary: an
 * order number or payment reference belonging to another store must resolve to
 * 404, never to someone else's order — dropping the store_id filter (or the
 * order.store_id whereHas on the reference read) would let one storefront
 * pay for, or inspect, another store's order. The gateway read is scoped for
 * the same reason: charging through another store's keys would move money
 * into the wrong account.
 *
 * Those two reads take an optional customer as well, which adds the *ownership*
 * boundary inside the store — see scopeToCustomer() for what it does with an
 * order that names nobody.
 *
 * Query building only — no abort() calls and no transaction boundaries; the
 * 409/422 refusals and the settlement workflow belong to the controller and
 * CheckoutPaymentService.
 */
final class CheckoutRepository
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    /**
     * The payment options this storefront offers.
     *
     * This used to fall back to **every platform-active method** when a store
     * had no assignment of its own. That was dangerous, not merely untidy: an
     * unconfigured store would be shown a gateway its business had never
     * connected, and because the key lookup was independently broken, the
     * customer's money went through the *platform's* account. An unconfigured
     * store must offer nothing, never someone else's gateway.
     *
     * The resolver answers the question properly — the store's own override,
     * else its business's default, else nothing — and also enforces the
     * currency match and credential completeness that the old query ignored.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function paymentMethodsFor(Store $store): Collection
    {
        $usable = $this->gateways->forStore($store);

        if ($usable === []) {
            return new Collection;
        }

        return PaymentMethod::query()
            ->whereIn('code', array_keys($usable))
            ->orderBy('id')
            ->get();
    }

    /**
     * The store's verified bank accounts, for the transfer instructions.
     *
     * @return Collection<int, StoreBank>
     */
    public function verifiedBankAccountsFor(Store $store): Collection
    {
        return $store->assignedBanks()->where('is_verified', true)->get();
    }

    /**
     * One order by its public number, scoped to the storefront being visited.
     * `firstOrFail` keeps the controller's 404 for an unknown number; a number
     * that exists in another store also 404s here. The caller passes any
     * relations the payload reads, so the plain and detailed lookups share one
     * definition.
     *
     * `$customer` narrows that further, for the callers where the order is
     * about to be paid rather than merely read. It stays optional because order
     * *tracking* is a public feature — the track page has no session to scope
     * by, and scoping it would break it — so a null customer means "the public
     * read" and applies no ownership condition at all.
     *
     * @param  array<int, string>  $with
     */
    public function findOrderByNumber(Store $store, string $orderNumber, array $with = [], ?Customer $customer = null): Order
    {
        return $this->scopeToCustomer(
            Order::query()
                ->where('store_id', $store->id)
                ->where('order_number', $orderNumber),
            $customer,
        )
            ->with($with)
            ->firstOrFail();
    }

    /**
     * One transaction by reference, reachable only through an order of the
     * storefront being visited — a reference from another store 404s instead
     * of being verifiable here.
     *
     * `$customer` adds the same ownership check the order lookup takes, for the
     * same reason: verifying a payment moves money, so it must be the payer's
     * own reference, not merely one within the right store.
     */
    public function findTransactionByReference(Store $store, string $reference, ?Customer $customer = null): Transaction
    {
        return Transaction::query()
            ->where('reference', $reference)
            ->whereHas('order', fn ($q) => $this->scopeToCustomer(
                $q->where('store_id', $store->id),
                $customer,
            ))
            ->firstOrFail();
    }

    /**
     * The ownership condition, shared by the two reads a payment goes through.
     *
     * An order that names a customer belongs to that customer and 404s for
     * anybody else.
     *
     * An order with *no* customer stays reachable by whoever holds its number,
     * which is exactly what it was before accounts were required. That is not a
     * gap left open so much as the absence of anything to compare against:
     * `orders` carries no email and neither does the address snapshot, so there
     * is no field to match a claimant on and refusing outright would strand
     * those rows forever rather than protect anybody. There are real ones — a
     * quarter of the orders in this database predate the account requirement.
     * Paying a stranger's ownerless order is a gift, not a theft, and the
     * number remains the only way in.
     */
    private function scopeToCustomer($query, ?Customer $customer)
    {
        if (! $customer) {
            return $query;
        }

        return $query->where(function ($q) use ($customer) {
            $q->where('customer_id', $customer->id)->orWhereNull('customer_id');
        });
    }

    /**
     * The order's download rows with their products, minus any whose product
     * has been hard-deleted — the inline filter's exact membership rule.
     *
     * The caller invokes this only for a fully paid order; that entitlement
     * check stays at the response's composition point, not here.
     *
     * @return Collection<int, DigitalDownload>
     */
    public function downloadsFor(Order $order): Collection
    {
        return $order->digitalDownloads()
            ->with('product')
            ->get()
            ->filter(fn (DigitalDownload $download) => $download->product !== null);
    }
}
