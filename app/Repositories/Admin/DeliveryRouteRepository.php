<?php

namespace App\Repositories\Admin;

use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-17 — platform delivery-route reads (admin console).
 *
 * Admin-managed routes are the platform-wide defaults the checkout fallback
 * resolves to; store-scoped rows belong to the businesses that own them and
 * are managed elsewhere, so every query here rides `store_id IS NULL`.
 *
 * This layer builds queries and never opens a transaction and never calls
 * abort(): the single-row writes stay on the controller (running them through
 * a service would be indirection with no benefit) and the controller owns the
 * HTTP status each refusal maps to. The list query, the unfiltered summary
 * aggregates and the cross-table delete-usage counts are each composed enough
 * to earn their place.
 */
final class DeliveryRouteRepository
{
    /**
     * Columns the list may be sorted by; anything else is rejected by
     * ListDeliveryRoutesRequest before it could reach `orderBy` (same class of
     * bug as the legacy order-index sort injection).
     *
     * @var array<int, string>
     */
    public const SORTABLE = ['country', 'state', 'area', 'fee', 'delivery_days', 'active', 'created_at', 'updated_at'];

    /**
     * The filtered, sorted, paginated console list. `$filters` arrive
     * validated, so only `q`/`status`/`sort`/`direction`/`per_page` reach the
     * query.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $query = $this->platformQuery()
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $query->where(function (Builder $query) use ($term) {
                    $query->where('country', 'like', "%{$term}%")
                        ->orWhere('state', 'like', "%{$term}%")
                        ->orWhere('area', 'like', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('active', $status === 'active'));

        // Legacy order was country → state → area; a chosen sort column leads
        // and the legacy reading order breaks ties.
        $sort = $filters['sort'] ?? null;

        if ($sort === null) {
            $query->orderBy('country')->orderBy('state')->orderBy('area');
        } else {
            $query->orderBy($sort, $filters['direction'] ?? 'asc');

            foreach (['country', 'state', 'area'] as $tieBreak) {
                if ($tieBreak !== $sort) {
                    $query->orderBy($tieBreak);
                }
            }
        }

        return $query->orderBy('id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    /**
     * The screen's status pills: unfiltered totals so they stay stable while a
     * search narrows the table.
     *
     * @return array{total: int, active: int, inactive: int, states: int}
     */
    public function summary(): array
    {
        $base = $this->platformQuery();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('active', true)->count(),
            'inactive' => (clone $base)->where('active', false)->count(),
            'states' => (clone $base)->distinct()->count('state'),
        ];
    }

    /**
     * What still references a route: orders (soft-deleted included — they keep
     * the id), order-delivery records and saved addresses. Returned as counts
     * so the controller phrases the refusal and picks its status code.
     *
     * @return array{orders: int, delivery_records: int, saved_addresses: int}
     */
    public function usageCounts(DeliveryRoute $route): array
    {
        return [
            'orders' => Order::withTrashed()->where('delivery_route_id', $route->id)->count(),
            'delivery_records' => OrderDelivery::query()->where('delivery_route_id', $route->id)->count(),
            'saved_addresses' => DeliveryAddress::query()->where('delivery_route_id', $route->id)->count(),
        ];
    }

    /**
     * The scope every read shares: platform-wide defaults only, store-scoped
     * rows deliberately invisible.
     */
    private function platformQuery(): Builder
    {
        return DeliveryRoute::query()->whereNull('store_id');
    }
}
