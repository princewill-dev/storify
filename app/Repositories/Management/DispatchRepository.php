<?php

namespace App\Repositories\Management;

use App\Models\OrderDelivery;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-26 — reads for the dispatches board.
 *
 * The legacy screen (`Management\DispatchesController@index`) was a read-only
 * board over `OrderDelivery`: four metric cards, a free-text search across
 * driver / tracking / order number, a filter modal (status, store, date range)
 * and a table whose rows link back to the order. The query composition behind
 * all of it lives here.
 *
 * Scoping note: legacy computed its metric cards from a raw business-wide
 * `OrderDelivery` query, so restricted staff saw counts for stores they cannot
 * open. Every query here runs through `accessibleQuery()`, which applies both
 * the business and the accessible-store scope.
 *
 * Query building only: this layer never opens a transaction and never calls
 * abort() — the controller owns the 403 for a foreign store id.
 */
final class DispatchRepository
{
    /** Legacy grouped these two as "Pending". */
    private const PENDING_STATUSES = ['pending', 'assigned'];

    /** Legacy grouped these three as "In Transit". */
    private const IN_TRANSIT_STATUSES = ['picked_up', 'in_transit', 'out_for_delivery'];

    /** Legacy grouped these two as its computed-but-unused "Failed" counter. */
    private const FAILED_STATUSES = ['failed', 'returned'];

    /** A delivery is "open" while it is in none of these. Drives WS-34's nav badge. */
    private const CLOSED_STATUSES = ['delivered', 'failed', 'returned'];

    /**
     * The filtered, paginated board: legacy's search, status/store/date
     * filters, eager loads and newest-first ordering. The `q`/`search`,
     * `from`/`date_from` and `to`/`date_to` pairs are aliases — the legacy
     * spellings win only when the modern ones are absent.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        $search = $filters['q'] ?? $filters['search'] ?? null;
        $from = $filters['from'] ?? $filters['date_from'] ?? null;
        $to = $filters['to'] ?? $filters['date_to'] ?? null;

        return $this->accessibleQuery($user)
            ->with([
                'order:id,order_number,status,store_id,customer_id,source,total',
                'order.store:id,name',
                'order.customer:id,first_name,last_name',
                'deliveryRoute:id,area,state',
            ])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->whereHas(
                'order',
                fn ($order) => $order->where('store_id', $storeId),
            ))
            ->when($search, function ($query, $term) {
                $like = '%'.$term.'%';

                // Legacy's search matched driver name, tracking number and the
                // linked order number.
                $query->where(fn ($inner) => $inner->where('driver_name', 'like', $like)
                    ->orWhere('tracking_number', 'like', $like)
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', $like)));
            })
            ->when($from, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($to, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The legacy metric cards, business-wide (not narrowed by the active
     * filters — same as the orders board), but store-scoped for restricted
     * staff. `open` follows the legacy sidebar badge definition
     * (`status NOT IN (delivered, failed, returned)`) for WS-34 to consume.
     *
     * @return array<string, int>
     */
    public function stats(User $user): array
    {
        $query = $this->accessibleQuery($user);

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $sum = fn (array $statuses) => (int) array_sum(
            array_map(fn (string $status) => (int) ($counts[$status] ?? 0), $statuses),
        );

        $total = (int) $counts->sum();
        $failed = $sum(self::FAILED_STATUSES);

        return [
            'total' => $total,
            'pending' => $sum(self::PENDING_STATUSES),
            'in_transit' => $sum(self::IN_TRANSIT_STATUSES),
            // Legacy measured this on the actual delivery time, not the status
            // change time — a dispatch delivered yesterday but touched today
            // must not count.
            'delivered_today' => (clone $query)
                ->where('status', 'delivered')
                ->whereDate('actual_delivery_at', today())
                ->count(),
            // Computed by legacy and never rendered; the board surfaces it.
            'failed' => $failed,
            'open' => $total - $sum(self::CLOSED_STATUSES),
        ];
    }

    /**
     * Filter-modal store list — the same accessible, non-deleted stores legacy
     * passed to its view.
     *
     * @return Collection<int, Store>
     */
    public function storeOptions(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            // Qualified because restricted staff read this through the
            // staff_assignments pivot, whose own `id` would be ambiguous.
            ->get(['stores.id', 'stores.name']);
    }

    /**
     * Deliveries for every store this user can reach. Excluding soft-deleted
     * orders via the relation also keeps a deleted order's delivery off the
     * board, the way WS-06 keeps deleted warehouses out of its lists.
     */
    private function accessibleQuery(User $user): Builder
    {
        return OrderDelivery::query()
            ->where('business_id', $user->business_id)
            ->whereHas('order', fn (Builder $order) => $order->whereIn('store_id', $user->accessibleStoreIds()));
    }
}
