<?php

namespace App\Repositories\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * WS-10 (admin console) — admin directory and platform-role queries.
 *
 * This layer builds queries and applies filters; it never opens a transaction
 * and never calls abort() — transaction boundaries belong to
 * AdminAccountService and the controller owns the HTTP status each refusal
 * maps to.
 */
final class AdminAccountRepository
{
    /**
     * The platform account roles this console manages. Everything else in the
     * users table is a tenant account and is WS-8's surface.
     */
    public const PLATFORM_ROLES = [User::ROLE_SUPERADMIN, User::ROLE_ADMIN];

    /**
     * The one platform role that can never be assigned or managed. Super Admin
     * is seeded with every permission and is deliberately absent from the
     * assignable-role list (legacy: `where('name', '!=', 'Super Admin')`).
     */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * Columns the directory may be sorted by. Never pass a request-supplied
     * column straight to orderBy (admin roadmap §3.3).
     */
    public const SORTABLE = ['name', 'email', 'status', 'invited_at', 'last_login_at', 'created_at'];

    /**
     * The admin directory. Legacy showed every platform account newest-first
     * with no filters; this keeps that ordering and adds the search/status
     * filters and pagination the modern lists use.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->whereIn('role', self::PLATFORM_ROLES)
            // No column restriction: Spatie's team constraint reads the pivot
            // columns, and a `roles:id,name` select drops them.
            ->with('roles');

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';
            $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('account_code', 'like', $term));
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The legacy stat pills the list needed but never had: total, pending and
     * active platform accounts.
     *
     * @return array{total: int, pending: int, active: int}
     */
    public function stats(): array
    {
        $counts = User::query()
            ->whereIn('role', self::PLATFORM_ROLES)
            ->selectRaw("COUNT(*) as total, SUM(status = 'invited') as pending, SUM(status = 'active') as active")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'active' => (int) ($counts->active ?? 0),
        ];
    }

    /**
     * The platform roles an admin can be invited with / moved to, ordered by
     * name. Legacy populated the row select from exactly this query; the SPA
     * consumes it so the contract can never drift from the seeder.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(): Collection
    {
        return $this->assignableRoleQuery()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Resolve an assignable platform role by name. Null means the caller chose
     * a role this console cannot hand out; the controller answers the
     * deliberate 422.
     */
    public function platformRole(string $name): ?Role
    {
        return $this->assignableRoleQuery()
            ->where('name', $name)
            ->first();
    }

    /**
     * The predicate both role lookups ride: global (`business_id` null), `web`
     * guard, Super Admin excluded (it is granted, never handed out). Shared so
     * the select list and the resolver can never drift — accepting a
     * business-scoped role here would let the console hand out tenant roles.
     *
     * @return Builder<Role>
     */
    private function assignableRoleQuery(): Builder
    {
        return Role::query()
            ->whereNull('business_id')
            ->where('guard_name', 'web')
            ->where('name', '!=', self::SUPER_ADMIN_ROLE);
    }
}
