<?php

namespace App\Repositories\Admin;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-4 (admin console) — curated business-type queries.
 *
 * The BusinessType model carries no relations (it is shared and off limits for
 * this workstream), so reference counts come from the referencing tables.
 * This layer builds queries; it never opens a transaction and never calls
 * abort() — the controller owns the HTTP status each guard refusal maps to.
 */
final class BusinessTypeRepository
{
    /**
     * The directory: `q` filters on name, then alphabetical order.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateList(array $filters): LengthAwarePaginator
    {
        return BusinessType::query()
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.trim($filters['q']).'%'))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * @return array{businesses: int, stores: int}
     */
    public function countsFor(int $typeId): array
    {
        return [
            'businesses' => Business::query()->where('business_type_id', $typeId)->count(),
            'stores' => Store::query()->where('business_type_id', $typeId)->count(),
        ];
    }

    /**
     * The delete guard: a type still referenced by a business or a store may
     * not be removed — legacy deleted freely and left every business/store
     * pointing at a row that no longer existed.
     */
    public function isInUse(int $typeId): bool
    {
        $counts = $this->countsFor($typeId);

        return $counts['businesses'] > 0 || $counts['stores'] > 0;
    }

    /**
     * One grouped count per referencing table, for the page's type ids.
     *
     * @param  class-string  $model
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function referenceCounts(string $model, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->whereIn('business_type_id', $ids)
            ->selectRaw('business_type_id, COUNT(*) as aggregate')
            ->groupBy('business_type_id')
            ->pluck('aggregate', 'business_type_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
