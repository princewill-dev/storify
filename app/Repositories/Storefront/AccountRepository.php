<?php

namespace App\Repositories\Storefront;

use App\Models\Customer;
use App\Models\DigitalDownload;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Reads behind the customer account endpoints — the order history, one order's
 * detail and the digital downloads list.
 *
 * Every query here is scoped to a single customer id: that where() is the
 * account API's tenancy boundary, and losing it would leak another customer's
 * order history or download tokens. Query building only — no transaction, no
 * abort(), and no entitlement decisions; the fully-paid gate on an order's
 * downloads stays with the controller, at the point the response is composed.
 */
final class AccountRepository
{
    /**
     * The order list: customer-scoped, the store column subset eager-loaded,
     * newest first.
     *
     * The status filter is applied only when the caller passes a value from a
     * filled() check; null means "not filled" and skips the filter, exactly as
     * the inline when() did.
     *
     * @return Builder<Order>
     */
    public function ordersQuery(Customer $customer, ?string $status): Builder
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->with(['store:id,name,slug'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->latest();
    }

    /**
     * One order by its public number, scoped to the customer's own rows: a
     * number belonging to another account resolves to a 404, never to someone
     * else's order. The detail payload's relations are eager-loaded here.
     */
    public function findOrderForCustomer(Customer $customer, string $orderNumber): Order
    {
        return Order::query()
            ->where('order_number', $orderNumber)
            ->where('customer_id', $customer->id)
            ->with(['items', 'store:id,name,slug', 'transactions'])
            ->firstOrFail();
    }

    /**
     * The order detail's downloads read: the rows with their product, minus
     * any whose product has been hard-deleted — the inline filter's exact
     * membership rule.
     *
     * The caller invokes this only for a fully paid order; that entitlement
     * check stays at the response's composition point, not here.
     *
     * @return EloquentCollection<int, DigitalDownload>
     */
    public function downloadsFor(Order $order): EloquentCollection
    {
        return $order->digitalDownloads()
            ->with('product')
            ->get()
            ->filter(fn (DigitalDownload $download) => $download->product !== null);
    }

    /**
     * The downloads list: customer-scoped, the product column subset
     * eager-loaded, newest first.
     *
     * @return Builder<DigitalDownload>
     */
    public function downloadsQuery(Customer $customer): Builder
    {
        return DigitalDownload::query()
            ->where('customer_id', $customer->id)
            ->with(['product:id,name'])
            ->latest();
    }
}
