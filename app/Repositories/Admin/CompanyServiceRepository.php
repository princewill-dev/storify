<?php

namespace App\Repositories\Admin;

use App\Models\CompanyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-18 (admin console) — company-service reads.
 *
 * This layer builds queries and never opens a transaction and never calls
 * abort(): the row/file writes and their transaction boundaries live in
 * CompanyServiceLifecycleService, and the controller owns the HTTP shape. The
 * table is platform-wide marketing content, so nothing here is tenant-scoped.
 */
final class CompanyServiceRepository
{
    /**
     * The console list: the `q` search across title, description and page
     * link, the `status` filter, a whitelisted sort (never a raw column) and
     * pagination with the query string preserved.
     *
     * The `id` tiebreak is load-bearing: it keeps the paginated drag-reorder
     * stable when rows share an `order` value.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? 'order';
        $direction = $filters['direction'] ?? 'asc';

        return CompanyService::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('page_link', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            // Stable tiebreak for paginated drag-reorder.
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * One aggregate instead of four counts — the screen shows total / active /
     * inactive and how many rows carry a page link.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = CompanyService::query()->selectRaw("
            count(*) as total,
            sum(case when status = 'active' then 1 else 0 end) as active,
            sum(case when status = 'inactive' then 1 else 0 end) as inactive,
            sum(case when page_link is not null then 1 else 0 end) as linked
        ")->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
            'linked' => (int) ($counts->linked ?? 0),
        ];
    }

    /**
     * The submitted ids that no longer exist — the reorder pre-flight.
     *
     * This is the guard, not a convenience wrapper: the console reports
     * unknown ids as a 422 before the transaction starts (legacy polluted
     * nothing but silently skipped them), and computing the diff against the
     * stored set is what makes the anti-id-probing refusal exact. The
     * controller owns the message and the status it maps to.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int|string>
     */
    public function missingIds(array $ids): array
    {
        $existing = CompanyService::query()->whereIn('id', $ids)->pluck('id')->all();

        return array_values(array_diff($ids, $existing));
    }
}
