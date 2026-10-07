<?php

namespace App\Services\Management\Warehouse;

use App\Models\Section;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds — or lazily creates — the warehouse a business falls back to when a
 * physical product is saved without one being chosen.
 *
 * Requiring a warehouse up front asked every new user to understand
 * logistics before they could list their first product. The rule itself is
 * sound (physical stock has to live somewhere, or receiving, transfers and
 * stock counts have nothing to point at), so instead of dropping it the write
 * path resolves a target. This class is that resolution.
 *
 * The warehouse it creates is deliberately unremarkable: it is created once,
 * named after the business, given a single "General" section, and from then on
 * behaves like any warehouse the user created by hand. They can rename it,
 * empty it or delete it. Deleting it (see WarehouseService::delete, which
 * clears the flag) simply means the next warehouse-less product gets a fresh
 * one.
 *
 * @see Warehouse::$is_default for the column's NULL-means-ordinary convention.
 */
final class DefaultWarehouseResolver
{
    private const SECTION_NAME = 'General';

    private const FALLBACK_NAME = 'Main';

    /**
     * The warehouse to file a warehouse-less physical product into, or null
     * when this caller must not or cannot have one.
     *
     * A null return is not a failure — it leaves the caller's existing "assign
     * the product to a warehouse" rejection exactly as it was.
     */
    public function resolve(User $user): ?Warehouse
    {
        $businessId = $user->business_id;

        if ($businessId === null) {
            return null;
        }

        $businessId = (int) $businessId;

        if ($existing = $this->find($user, $businessId)) {
            return $existing;
        }

        // Restricted staff only reach warehouses assigned to them, so one
        // created here would be invisible to the very next line of the request
        // — assignmentError() would reject it as an invalid selection. Let the
        // caller keep its 422 rather than writing a row nobody can use.
        if ($user->isRestrictedStaff()) {
            return null;
        }

        try {
            return $this->create($user, $businessId);
        } catch (UniqueConstraintViolationException) {
            // unique(business_id, is_default) refused the second insert, so a
            // concurrent save won the race. Its warehouse — section included —
            // is the answer, not ours.
            return $this->find($user, $businessId);
        }
    }

    /**
     * Scoped through accessibleWarehouses(), never a bare business_id query:
     * the writer and the validator have to agree, and assignmentError() checks
     * this same relation. For an ordinary owner that relation is hasMany on
     * user_id, which is why create() sets user_id to the business owner.
     *
     * business_id is filtered as well because a platform admin's branch of
     * accessibleWarehouses() is platform-wide.
     */
    private function find(User $user, int $businessId): ?Warehouse
    {
        return $user->accessibleWarehouses()
            ->where('warehouses.business_id', $businessId)
            ->where('warehouses.is_default', true)
            ->where('warehouses.status', '!=', Warehouse::STATUS_DELETED)
            ->first();
    }

    /**
     * The warehouse and its section are written together — a fallback with no
     * section would give the sections panel nothing to show and leave the
     * product filed nowhere in particular.
     */
    private function create(User $user, int $businessId): Warehouse
    {
        $warehouse = DB::transaction(function () use ($user, $businessId) {
            $warehouse = Warehouse::create([
                // The owner, not the acting user. accessibleWarehouses() is
                // hasMany(user_id) for a non-staff owner, so a warehouse
                // created under a staff member would never appear in the
                // owner's own warehouse list.
                'user_id' => $user->business?->user_id ?? $user->id,
                'business_id' => $businessId,
                'name' => $this->name($user),
                'status' => Warehouse::STATUS_ACTIVE,
                'is_default' => true,
            ]);

            Section::create([
                'warehouse_id' => $warehouse->id,
                'business_id' => $businessId,
                'name' => self::SECTION_NAME,
                'status' => Section::STATUS_ACTIVE,
            ]);

            return $warehouse;
        });

        Log::info('api.management.default_warehouse_created', [
            'user_id' => $user->id,
            'business_id' => $businessId,
            'warehouse_id' => $warehouse->id,
        ]);

        return $warehouse;
    }

    /**
     * "Pets Cafes warehouse" — the business's own name, so the user recognises
     * it on the warehouses page. Derived once, at creation: renaming the
     * business later does not rename the warehouse, the user does that
     * themselves.
     */
    private function name(User $user): string
    {
        $business = trim((string) $user->business?->name);

        // Truncated so the suffix cannot push the name past the column limit.
        return mb_substr($business !== '' ? $business : self::FALLBACK_NAME, 0, 200).' warehouse';
    }
}
