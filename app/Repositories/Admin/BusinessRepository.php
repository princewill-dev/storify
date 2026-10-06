<?php

namespace App\Repositories\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * WS-4 (admin console) — business directory and lifecycle queries.
 *
 * This layer builds queries and applies single-model persists; it never opens
 * a transaction and never calls abort() — transaction boundaries belong to
 * BusinessLifecycleService, and the controller owns the HTTP status each
 * guard refusal maps to. Database-level guards (the main-store check and the
 * open-orders/transactions checks) live here so the service and the
 * controller ask the same question of the same query.
 */
final class BusinessRepository
{
    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the `deleted` option
     * (with deleted rows hidden by default, like the legacy list), the
     * warehouses count, and `q` matching the owner phone the legacy
     * placeholder advertised.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        $query = Business::query()
            ->with([
                'owner:id,name,email,phone,account_code,status,is_verified',
                'activeSubscription.subscriptionPlan:id,name',
            ])
            // Deleted stores/warehouses are not part of what the business runs
            // today; counting them made the legacy numbers disagree with the
            // store and warehouse screens.
            ->withCount([
                'stores as stores_count' => fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
                'warehouses as warehouses_count' => fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
                'users as users_count',
            ]);

        // Legacy default: deleted rows stay out of the directory unless the
        // admin explicitly asks for them.
        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $query->where('status', $status);
        } elseif (! ($filters['include_deleted'] ?? false)) {
            $query->where('status', '!=', 'deleted');
        }

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';

            $query->where(fn ($inner) => $inner->where('name', 'like', $term)
                ->orWhere('business_code', 'like', $term)
                ->orWhereHas('owner', fn ($owner) => $owner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)));
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // Whitelisted above — never pass a request-supplied column to orderBy.
        $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    /**
     * The platform main store's owner may not be suspended or deleted
     * (roadmap §3.7). WS-6/WS-8 carry the same rule for the store and the
     * user; it is inlined here rather than shared so concurrent workstreams
     * cannot overwrite one another's copy mid-flight.
     */
    public function ownsMainStore(Business $business): bool
    {
        $mainStoreId = Setting::query()->value('main_store_id');

        if (! $mainStoreId) {
            return false;
        }

        return $business->stores()->whereKey($mainStoreId)->exists();
    }

    /**
     * Live store ids for a business. The delete guards resolve the list once
     * so the order and transaction checks run against the same set.
     *
     * @return array<int, int>
     */
    public function liveStoreIds(Business $business): array
    {
        return $business->stores()->where('status', '!=', Store::STATUS_DELETED)->pluck('id')->all();
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
     * Legacy's multi-business guard reduced to "a superadmin exists" (its
     * email comparison also matched the superadmins themselves).
     */
    public function superadminExists(): bool
    {
        return User::query()->where('role', User::ROLE_SUPERADMIN)->exists();
    }

    /**
     * Legacy normalised the slug from the name on every save
     * (lower-case, spaces to underscores) but let the database's unique index
     * blow up on a collision; a numeric suffix keeps the save succeeding.
     */
    public function uniqueSlug(string $name, ?int $ignoreBusinessId = null): string
    {
        $base = Str::of($name)->trim()->lower()->replace(' ', '_')->replaceMatches('/[^a-z0-9_]+/', '')->value();

        if ($base === '') {
            $base = 'business';
        }

        $slug = $base;
        $suffix = 1;

        while (Business::query()
            ->where('slug', $slug)
            ->when($ignoreBusinessId !== null, fn ($query) => $query->whereKeyNot($ignoreBusinessId))
            ->exists()) {
            $slug = $base.'_'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOwner(array $attributes): User
    {
        return User::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBusiness(array $attributes): Business
    {
        return Business::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBusiness(Business $business, array $attributes): void
    {
        $business->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOwner(User $owner, array $attributes): void
    {
        $owner->update($attributes);
    }

    /**
     * Link the freshly provisioned owner to their business. The write is
     * forceFill + save, exactly as the create flow always did it.
     */
    public function linkOwnerToBusiness(User $owner, Business $business): void
    {
        $owner->forceFill(['business_id' => $business->id])->save();
    }

    public function markOwnerEmailVerified(User $owner): void
    {
        $owner->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();
    }
}
