<?php

namespace App\Repositories\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-8 (admin console) — user directory, detail-console reads and the
 * moderation guards' queries.
 *
 * This layer builds queries and applies filters; it never opens a transaction
 * and never calls abort() — transaction boundaries belong to
 * UserModerationService, and the controller owns the HTTP status each guard
 * refusal maps to. The detail console's raw blocks are assembled here so
 * response shaping (UserDetailResource) issues no queries of its own.
 */
final class UserModerationRepository
{
    /**
     * Roles this console manages. Platform admins are WS-10's surface and are
     * never reachable here (legacy `ensureManaged()` 404s them too).
     */
    public const MANAGED_ROLES = [User::ROLE_BUSINESS_OWNER, 'staff'];

    /**
     * Columns the directory may be sorted by. Never pass a request-supplied
     * column straight to orderBy (admin roadmap §3.3).
     */
    public const SORTABLE = ['name', 'email', 'role', 'status', 'last_login_at', 'created_at'];

    /**
     * The platform user directory. Legacy defaulted to owners when no role was
     * chosen; `role=all` (or an empty role) is the explicit "every managed
     * role" option the previous SPA silently used.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->whereIn('role', self::MANAGED_ROLES)
            ->with(['business:id,name,business_code,status', 'business.activeSubscription.subscriptionPlan:id,name'])
            ->when($this->roleFilter($filters), fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(($filters['verified'] ?? null) !== null, fn ($q) => $q->where('is_verified', $this->truthy($filters['verified'])))
            ->when(($filters['has_business'] ?? null) === 'yes', fn ($q) => $q->whereNotNull('business_id'))
            ->when(($filters['has_business'] ?? null) === 'no', fn ($q) => $q->whereNull('business_id'))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function ($q) use ($filters) {
                $term = '%'.trim($filters['q']).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('account_code', 'like', $term));
            });

        $this->applySubscriptionFilter($query, $filters['subscription'] ?? null);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The directory's stat strip. Counted over the managed roles only, so
     * platform admins never leak into the tile.
     *
     * @return array{owners: int, staff: int, suspended: int, unverified: int}
     */
    public function stats(): array
    {
        return [
            'owners' => User::query()->where('role', User::ROLE_BUSINESS_OWNER)->where('status', '!=', 'deleted')->count(),
            'staff' => User::query()->where('role', 'staff')->where('status', '!=', 'deleted')->count(),
            'suspended' => User::query()->whereIn('role', self::MANAGED_ROLES)->where('status', 'suspended')->count(),
            'unverified' => User::query()->whereIn('role', self::MANAGED_ROLES)->where('is_verified', false)->where('status', '!=', 'deleted')->count(),
        ];
    }

    /**
     * The platform main store's owner may not be suspended or deleted
     * (roadmap §3.7). Kept inline so concurrent workstreams each carry their
     * own copy rather than racing on a shared helper.
     */
    public function ownsMainStore(User $user): bool
    {
        $mainStoreId = Setting::query()->value('main_store_id');

        if (! $mainStoreId) {
            return false;
        }

        return $user->stores()->whereKey($mainStoreId)->exists();
    }

    /**
     * Live store ids for the user. The delete guards resolve the list once so
     * the order and transaction checks run against the same set.
     *
     * @return array<int, int>
     */
    public function liveStoreIds(User $user): array
    {
        return $user->stores()->where('status', '!=', Store::STATUS_DELETED)->pluck('id')->all();
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    public function hasIncompleteOrders(array $storeIds): bool
    {
        return Order::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', '!=', OrderStatus::COMPLETED->value)
            ->exists();
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    public function hasIncompleteTransactions(array $storeIds): bool
    {
        return Transaction::query()
            ->whereHas('order', fn ($query) => $query->whereIn('store_id', $storeIds))
            ->where('status', '!=', TransactionStatus::CONFIRMED->value)
            ->exists();
    }

    /**
     * The detail console's eager loads: the business metrics (deleted stores
     * and warehouses are not part of what the business runs today) and the
     * subscription the plan label and subscription block read.
     */
    public function loadDetailRelations(User $user): User
    {
        return $user->load([
            'business' => fn ($q) => $q->withCount([
                'stores as stores_count' => fn ($inner) => $inner->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($inner) => $inner->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as team_count',
            ]),
            'business.activeSubscription.subscriptionPlan:id,name',
        ]);
    }

    /**
     * The raw rows behind the detail console's blocks, in the order the
     * console has always assembled them. Shaping happens in
     * UserDetailResource.
     *
     * @return array{
     *     stores: Collection<int, Store>,
     *     business_stores: Collection<int, Store>|null,
     *     orders_count: int,
     *     payments: Collection<int, Payment>,
     *     activity: Collection<int, ActivityLog>,
     *     active_impersonation: Impersonation|null
     * }
     */
    public function detailBlocks(User $user): array
    {
        $business = $user->business;

        return [
            'stores' => $user->accessibleStores()->get(['id', 'name', 'status']),
            'business_stores' => $business === null
                ? null
                : $business->stores()
                    ->where('status', '!=', Store::STATUS_DELETED)
                    ->orderBy('name')
                    ->get(['id', 'name', 'status']),
            'orders_count' => $business === null
                ? 0
                : Order::query()->where('business_id', $business->id)->count(),
            'payments' => $business === null
                ? collect()
                : Payment::query()
                    ->where('business_id', $business->id)
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'reference', 'amount', 'currency', 'status', 'payment_type', 'paid_at', 'created_at']),
            // Legacy's per-user feed: rows the user acted on plus rows about
            // the user.
            'activity' => ActivityLog::query()
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhere(fn ($inner) => $inner->where('subject_type', User::class)->where('subject_id', $user->id));
                })
                ->with('user:id,name')
                ->latest('id')
                ->limit(25)
                ->get(),
            'active_impersonation' => $this->activeImpersonationFor($user),
        ];
    }

    /**
     * The live session the detail console's "active impersonation" block
     * renders, with the impersonator name it shows.
     */
    public function activeImpersonationFor(User $user): ?Impersonation
    {
        return Impersonation::query()
            ->where('impersonated_id', $user->id)
            ->whereNull('ended_at')
            ->with('impersonator:id,name')
            ->latest('id')
            ->first();
    }

    /**
     * The open session the stop action targets — the latest one, or a
     * specific id when the caller names it. No impersonator eager load: the
     * stop response carries only the session's id and end time.
     */
    public function findOpenImpersonation(User $user, ?int $impersonationId): ?Impersonation
    {
        $query = Impersonation::query()
            ->where('impersonated_id', $user->id)
            ->whereNull('ended_at');

        if ($impersonationId) {
            $query->whereKey($impersonationId);
        }

        return $query->latest('id')->first();
    }

    /**
     * The access token the pair just issued: refresh tokens live in their own
     * table, so the latest `tokens` row really is the access token the
     * hand-off carries. Stored on the impersonation row so stop can revoke it.
     */
    public function latestAccessTokenId(User $user): string
    {
        return (string) $user->tokens()->latest('id')->value('id');
    }

    /**
     * Legacy defaulted the directory to owners; `all` (or an empty value) is
     * the explicit opt-out the SPA's role select offers.
     *
     * One deliberate widening: when the caller is running a free-text search
     * with no role chosen, every managed role is searched. The command palette
     * and the dashboard tiles deep-link `/users?q=…` with no role, and silently
     * hiding a staff member a support admin just searched for is the kind of
     * trap the audit called out.
     *
     * @param  array<string, mixed>  $filters
     */
    private function roleFilter(array $filters): ?string
    {
        $role = $filters['role'] ?? null;
        $searching = ($filters['q'] ?? null) !== null && $filters['q'] !== '';

        if ($role === null || $role === '') {
            return $searching ? null : User::ROLE_BUSINESS_OWNER;
        }

        return $role === 'all' ? null : $role;
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applySubscriptionFilter($query, ?string $subscription): void
    {
        if ($subscription === 'active') {
            $query->whereHas('business', fn ($business) => $business->whereHas('activeSubscription'));
        } elseif ($subscription === 'trial') {
            $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now());
        } elseif ($subscription === 'none') {
            $query->where(fn ($inner) => $inner->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now()))
                ->whereDoesntHave('business', fn ($business) => $business->whereHas('activeSubscription'));
        }
    }

    private function truthy(?string $value): bool
    {
        return in_array($value, ['1', 'yes'], true);
    }
}
