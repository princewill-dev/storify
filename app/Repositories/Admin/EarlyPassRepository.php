<?php

namespace App\Repositories\Admin;

use App\Models\EarlyPass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-11 (admin console) — early-pass directory and usage-history queries.
 *
 * This layer builds the queries and applies the eager loads; it never opens a
 * transaction and never calls abort() — the transaction boundaries belong to
 * EarlyPassService and the controller owns the HTTP status each refusal maps
 * to.
 */
final class EarlyPassRepository
{
    /**
     * The platform pass list: usage counts, the `q`/status filters and the
     * newest-first ordering the list has always used.
     *
     * @param  array<string, mixed>  $filters  validated by ListEarlyPassesRequest
     */
    public function paginateDirectory(array $filters, int $perPage): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return EarlyPass::query()
            ->withCount('usages')
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term) {
                $inner->where('code', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            }))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Usage history for one pass — redeemer, their business and the store the
     * redemption came from — newest first, as the detail page has always
     * shown it.
     */
    public function paginateUsages(EarlyPass $pass, int $perPage): LengthAwarePaginator
    {
        return $pass->usages()
            ->with([
                'user:id,account_code,name,email,business_id',
                'user.business:id,name,business_code',
                'store:id,store_id,name',
            ])
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Active/inactive pass counts for the list meta. One grouped aggregate,
     * not two counts, and the keys and totals are exactly what the payload
     * has always carried.
     *
     * @return array{active: int, inactive: int, all: int}
     */
    public function statusCounts(): array
    {
        $counts = EarlyPass::query()
            ->selectRaw('is_active, COUNT(*) as aggregate')
            ->groupBy('is_active')
            ->pluck('aggregate', 'is_active')
            ->map(fn ($count) => (int) $count);

        $active = $counts[1] ?? 0;
        $inactive = $counts[0] ?? 0;

        return [
            'active' => $active,
            'inactive' => $inactive,
            'all' => $active + $inactive,
        ];
    }
}
