<?php

namespace App\Repositories\Admin;

use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * AD-14 — the platform-wide transfer directory reads.
 *
 * Unlike the management repository, the platform console sees every
 * business's transfers, so nothing here carries a tenant predicate; the
 * platform-role guard on the controller is what limits access.
 *
 * Follows the repository rules: query building only, no transactions, no
 * abort(), and no request-supplied column reaches `orderBy` without the
 * request's whitelist.
 */
final class StockTransferRepository
{
    /**
     * The directory page size the pre-refactor controller used.
     */
    private const PER_PAGE = 15;

    /**
     * The directory: all eight statuses, `q` over the code or either location
     * name, with the row aggregates the list renders.
     *
     * @param  array{status?: string|null, q?: string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<StockTransfer>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->with(['fromLocation', 'toLocation', 'requester:id,name,email', 'business:id,name,business_code'])
            ->withCount('items')
            ->withSum('items as total_units', 'quantity')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Locations are polymorphic (Warehouse or Store), so the name
                // match is a per-type one rather than a plain whereHas.
                $q->where(function ($inner) use ($term) {
                    $inner->where('transfer_code', 'like', "%{$term}%")
                        ->orWhereHasMorph('fromLocation', [Warehouse::class, Store::class], fn ($location) => $location->where('name', 'like', "%{$term}%"))
                        ->orWhereHasMorph('toLocation', [Warehouse::class, Store::class], fn ($location) => $location->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Per-status counts for the directory badges. Never narrowed by the
     * active filter/search, so the totals stay stable.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        return StockTransfer::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
