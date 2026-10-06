<?php

namespace App\Services\Access;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scoping checks shared by the management API.
 *
 * The API had grown thirty-odd private `authorizeX()` methods that each
 * compared `business_id` and/or an accessible-store check, with their own
 * wording. They are not identical — four distinct shapes are in use:
 *
 *   business only          a role belongs to the business and nothing else
 *   store only             a store id, checked against what the user can reach
 *   business + store       an order or transfer
 *   business (nullable)    pre-migration rows carry a null business_id, so the
 *                          store check is the decisive scope for those
 *
 * Each shape gets its own method rather than one flag-driven method, because a
 * wrong combination here is an authorisation change, not a refactor.
 *
 * The message stays a parameter: every domain asserts its own subject ("this
 * order", "this transfer"), and those strings are asserted by tests.
 *
 * Deliberately NOT merged with StoreAccessService::allows(), which scopes by
 * isStaff() where this scopes by isRestrictedStaff() — for a staff member
 * holding `transactions view` the two disagree, so they are different rules
 * that happen to overlap.
 */
final class TenantGuard
{
    public function authorizeBusiness(Model $subject, ?User $user, string $message): void
    {
        if ((int) $subject->getAttribute('business_id') !== (int) $user?->business_id) {
            abort(403, $message);
        }
    }

    /**
     * For rows written before the multi-tenant migration, which carry a null
     * business_id. The store check is then the only meaningful scope.
     */
    public function authorizeNullableBusiness(Model $subject, ?User $user, string $message): void
    {
        $businessId = $subject->getAttribute('business_id');

        if ($businessId !== null && (int) $businessId !== (int) $user?->business_id) {
            abort(403, $message);
        }
    }

    public function authorizeStoreId(?User $user, int $storeId, string $message): void
    {
        if (! $user?->accessibleStores()->whereKey($storeId)->exists()) {
            abort(403, $message);
        }
    }

    public function authorizeStore(?User $user, Store $store, string $message): void
    {
        if ((int) $store->business_id !== (int) $user?->business_id
            || ! $user?->accessibleStores()->whereKey($store->getKey())->exists()) {
            abort(403, $message);
        }
    }

    /**
     * A row that belongs to a business AND sits inside a reachable store —
     * orders, transfers, dispatches.
     */
    public function authorizeBusinessAndStore(Model $subject, ?User $user, int $storeId, string $message): void
    {
        $this->authorizeBusiness($subject, $user, $message);
        $this->authorizeStoreId($user, $storeId, $message);
    }

    /**
     * Restricted-staff readable product: inside a store they can reach, or a
     * detached digital product with no store at all.
     */
    public function allowsProduct(?User $user, Model $product): bool
    {
        if ($user === null) {
            return false;
        }

        if ((int) $product->getAttribute('business_id') !== (int) $user->business_id) {
            return false;
        }

        $storeId = $product->getAttribute('store_id');

        if ($storeId === null) {
            return (bool) $product->getAttribute('is_digital');
        }

        return $user->accessibleStores()->whereKey($storeId)->exists();
    }
}
