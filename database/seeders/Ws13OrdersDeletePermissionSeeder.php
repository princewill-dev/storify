<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WS-13 — backfill the `orders delete` permission.
 *
 * routes/api/v1/management.php gates `DELETE orders/{order}` on
 * `permission:orders delete`, and the SPA gates its Delete button on the same
 * ability — but the permission was missing from the `orders` action list in
 * SpatiePermissionSeeder / SyncPermissions, so no role could hold it and the
 * endpoint was unreachable for everyone including the business owner (whose
 * business Super Admin role syncs "all permissions", but only those that
 * exist).
 *
 * The canonical maps now carry `delete` (the fix landed in both
 * database/seeders/SpatiePermissionSeeder.php and
 * app/Console/Commands/SyncPermissions.php), so new seeds and
 * `permissions:sync` create it. This seeder stays as the idempotent backfill
 * for databases seeded before that change, and the WS-13 feature test runs it
 * directly to prove the backfill path. It is safe to run any number of times.
 */
class Ws13OrdersDeletePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => 'orders delete',
            'guard_name' => 'web',
        ]);

        // Mirror permissions:sync — the platform Super Admin and every
        // business-level Super Admin / Developer role hold every permission.
        // Other roles (Manager, Store Manager…) can be granted it deliberately
        // by adding the action to their map in SpatiePermissionSeeder.
        $roles = Role::query()
            ->where(function ($query) {
                $query->where(fn ($inner) => $inner->where('name', 'Super Admin')->whereNull('business_id'))
                    ->orWhere(fn ($inner) => $inner->whereIn('name', ['Super Admin', 'Developer'])->whereNotNull('business_id'));
            })
            ->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
