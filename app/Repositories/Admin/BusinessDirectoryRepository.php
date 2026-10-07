<?php

namespace App\Repositories\Admin;

use App\Models\Business;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The directory query behind Api\V1\Admin\BusinessController — the older,
 * leaner `businesses` listing.
 *
 * Deliberately separate from `BusinessRepository`, whose
 * `paginateForDirectory()` carries the newer lifecycle console's filter set
 * (include_deleted, created-date range, an expanded sort whitelist,
 * withQueryString()). This endpoint validates nothing at the HTTP layer, so
 * its exact leniency is part of its contract; the two must not be merged —
 * the filter values arrive pre-interpreted by the controller and are applied
 * verbatim here.
 *
 * Follows the repository rules: builds the query and its eager loads, never
 * opens a transaction, never calls abort(), and never passes a
 * request-supplied column to orderBy without the whitelist below.
 */
final class BusinessDirectoryRepository
{
    /**
     * Columns the directory may be ordered by. Anything else falls back to
     * created_at.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'business_code', 'status', 'created_at'];

    /**
     * @param  array{status?: string|null, q?: string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        $query = Business::query()
            ->with([
                'owner:id,name,email,account_code',
                'activeSubscription.subscriptionPlan:id,name',
            ])
            ->withCount(['stores', 'warehouses', 'users'])
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['q'] ?? null) !== null, function ($q) use ($filters) {
                // No trim() here: the original built the pattern straight from
                // the query string, and LIKE already ignores the outer blanks.
                $term = '%'.$filters['q'].'%';

                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('business_code', 'like', $term)
                    ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            });

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        return $query
            ->orderBy($sort, ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }
}
