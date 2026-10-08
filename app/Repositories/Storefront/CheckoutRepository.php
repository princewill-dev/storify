<?php

namespace App\Repositories\Storefront;

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
     * @param  array<int, string>  $with
     */
    public function findOrderByNumber(Store $store, string $orderNumber, array $with = []): Order
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->where('order_number', $orderNumber)
            ->with($with)
            ->firstOrFail();
    }

    /**
     * One transaction by reference, reachable only through an order of the
     * storefront being visited — a reference from another store 404s instead
     * of being verifiable here.
     */
    public function findTransactionByReference(Store $store, string $reference): Transaction
    {
        return Transaction::query()
            ->where('reference', $reference)
            ->whereHas('order', fn ($q) => $q->where('store_id', $store->id))
            ->firstOrFail();
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
