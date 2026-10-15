<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WS-56 — backfill the three new `customers` permissions.
 *
 * The Customers section adds endpoints the existing `customers view` does not
 * cover: writing an internal note, exporting the customer list, and erasing a
 * customer on an approved deletion request. Gating them behind `edit` would
 * have been the smallest change and the wrong one — `edit` is held by roles
 * that have no business authorising an erasure, and an export is a copy of the
 * customer list leaving the platform, which is its own decision.
 *
 * The canonical maps carry them now (database/seeders/SpatiePermissionSeeder.php
 * and app/Console/Commands/SyncPermissions.php), so new seeds and
 * `permissions:sync` create them. This seeder remains the idempotent backfill
 * for databases seeded before that, and the WS-56 test runs it directly to
 * prove that path. Safe to run any number of times.
 *
 * Only Super Admin and Developer are granted them here, mirroring
 * permissions:sync. Manager and Customer Support reach the note permission
 * through the role map instead, so the grant is visible where roles are
 * defined rather than hidden in a migration-style seeder.
 */
class Ws56CustomerPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = collect(['note', 'export', 'erase'])
            ->map(fn (string $action) => Permission::firstOrCreate([
                'name' => 'customers '.$action,
                'guard_name' => 'web',
            ]));

        $roles = Role::query()
            ->where(function ($query) {
                $query->where(fn ($inner) => $inner->where('name', 'Super Admin')->whereNull('business_id'))
                    ->orWhere(fn ($inner) => $inner->whereIn('name', ['Super Admin', 'Developer'])->whereNotNull('business_id'));
            })
            ->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($permissions->all());
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
