<?php

namespace App\Repositories\Admin;

use App\Models\Testimonial;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-18 (admin console) — testimonial reads.
 *
 * This layer builds queries and never opens a transaction and never calls
 * abort(): the row/file writes and their transaction boundaries live in
 * TestimonialLifecycleService, and the controller owns the HTTP shape. The
 * table is platform-wide marketing content, so nothing here is tenant-scoped.
 */
final class TestimonialRepository
{
    /**
     * The console list: the `q` search across name, occupation and message,
     * the `status` filter, a whitelisted sort (never a raw column) and
     * pagination with the query string preserved.
     *
     * The `id` tiebreak is load-bearing: it keeps pagination from duplicating
     * or skipping a row when sort values tie. Legacy's `position` then
     * `created_at desc` had no final tiebreak.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? 'position';
        $direction = $filters['direction'] ?? 'asc';

        return Testimonial::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('occupation', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * One aggregate instead of four counts — the screen shows total / active /
     * inactive and how many rows still need the photo backfill.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = Testimonial::query()->selectRaw("
            count(*) as total,
            sum(case when status = 'active' then 1 else 0 end) as active,
            sum(case when status = 'inactive' then 1 else 0 end) as inactive,
            sum(case when photo like 'data:%' then 1 else 0 end) as legacy_photos
        ")->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
            'legacy_photos' => (int) ($counts->legacy_photos ?? 0),
        ];
    }

    /**
     * Rows that still carry a legacy `data:` photo, oldest first.
     *
     * The backfill's chunk walk and its post-run `remaining` count are the
     * same filter, so the definition lives once here — the walk and the count
     * can never disagree on what "still legacy" means. `chunkById` keeps the
     * walk bounded regardless of table size.
     *
     * @return Builder<Testimonial>
     */
    public function legacyPhotosQuery(): Builder
    {
        return Testimonial::query()
            ->where('photo', 'like', 'data:%')
            ->orderBy('id');
    }
}
