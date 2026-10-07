<?php

namespace App\Repositories\Admin;

use App\Models\Coupon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-12 (admin console) — coupon list queries.
 *
 * This layer builds queries; it never opens a transaction and never calls
 * abort(). The coupon writes are single-model persists, so they stay on the
 * controller and no service layer is warranted.
 *
 * The list keeps its legacy permissive posture on purpose: an unknown,
 * non-whitelisted `sort` silently falls back to `created_at`, any direction
 * other than `asc` is `desc`, and `per_page` is whatever integer the request
 * carried. That is also why the filters arrive as a plain array read from the
 * raw request rather than through a FormRequest — the endpoint has never
 * rejected a filter value, and turning one into a 422 would change what it
 * does.
 */
final class CouponRepository
{
    /**
     * Columns the list may be ordered by — a request-supplied column never
     * reaches orderBy() (the whitelist the legacy endpoint always had).
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['code', 'discount_value', 'uses_count', 'expires_at', 'is_active', 'created_at'];

    /**
     * The coupon console list: `q` over code, the whitelisted sort, then
     * pagination. The eager load is trimmed to the plan name the payload
     * reads, so the list never lazy-loads per row.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true)
            ? $filters['sort']
            : 'created_at';

        // Keep the search term itself: `when()` hands the callback its
        // condition (a bool) as the second argument, so the term must be
        // captured, not received as a callback parameter.
        $term = $filters['q'] ?? null;

        return Coupon::query()
            ->with('subscriptionPlan:id,name')
            ->when($term !== null, fn ($query) => $query->where('code', 'like', '%'.$term.'%'))
            ->orderBy($sort, ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }
}
