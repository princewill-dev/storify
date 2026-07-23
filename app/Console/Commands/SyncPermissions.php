<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';
    protected $description = 'Create any missing permissions and sync them to Super Admin and Developer roles';

    protected array $permissionGroups = [
        'dashboard'     => ['view'],
        'warehouses'    => ['view', 'create', 'edit', 'delete', 'transfer', 'receive', 'approve_transfer'],
        'products'      => ['view', 'create', 'edit', 'delete', 'stock_adjust'],
        'orders'        => ['view', 'create', 'edit', 'status_update', 'refund', 'cancel', 'assign_delivery'],
        'pos'           => ['open_session', 'process_sale', 'close_session', 'view_history', 'void_sale'],
        'staff'         => ['view', 'create', 'edit', 'delete', 'suspend', 'activate'],
        'stores'        => ['view', 'create', 'edit', 'delete', 'suspend', 'settings'],
        'customers'     => ['view', 'edit', 'suspend', 'message'],
        'transactions'  => ['view', 'confirm', 'reject', 'refund', 'export'],
        'invoices'      => ['view', 'create', 'edit', 'delete', 'send'],
        'reports'       => ['view_sales', 'view_inventory', 'view_staff', 'view_finance', 'export'],
        'settings'      => ['view', 'edit', 'payment', 'subscription'],
        'deliveries'    => ['view', 'update_status', 'manage_routes'],
        'support'       => ['view_tickets', 'reply', 'close'],
        'coupons'       => ['view', 'create', 'edit', 'delete'],
        'transfers'     => ['view', 'create', 'approve', 'dispatch', 'receive'],
    ];

    public function handle(): int
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';
        $created = 0;

        foreach ($this->permissionGroups as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group} {$action}";
                $perm = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
                if ($perm->wasRecentlyCreated) {
                    $created++;
                    $this->line("  <fg=bright-green>+</> {$name}");
                }
            }
        }

        $this->newLine();
        $this->info($created > 0 ? "{$created} new permission(s) created." : 'All permissions up to date.');
        $this->newLine();

        $allPermissions = Permission::all();

        $platformRole = Role::where('name', 'Super Admin')->whereNull('business_id')->first();
        if ($platformRole) {
            $platformRole->syncPermissions($allPermissions);
            $this->info('Synced platform Super Admin: ' . $allPermissions->count() . ' permissions.');
        }

        $businessRoles = Role::whereIn('name', ['Super Admin', 'Developer'])->whereNotNull('business_id')->get();

        foreach ($businessRoles as $role) {
            $role->syncPermissions($allPermissions);
        }

        $syncCount = $businessRoles->count();
        if ($syncCount > 0) {
            $this->info("Synced {$syncCount} business-level Super Admin / Developer role(s).");
        } elseif (!$platformRole) {
            $this->warn('No Super Admin or Developer roles found. Run the SpatiePermissionSeeder first.');
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->newLine();
        $this->info('Done.');

        return Command::SUCCESS;
    }
}
