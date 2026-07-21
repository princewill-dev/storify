<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\Business;
use App\Models\User;

class SpatiePermissionSeeder extends Seeder
{
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

    protected array $roles = [
        'super_admin' => [
            'name' => 'Super Admin',
            'description' => 'Full unrestricted access to everything.',
            'permissions' => 'all',
        ],
        'managing_director' => [
            'name' => 'Managing Director',
            'description' => 'Strategic oversight: branch performance, sales, stock analytics, executive dashboard.',
            'permissions' => [
                'dashboard'    => ['view'],
                'stores'       => ['view'],
                'products'     => ['view'],
                'orders'       => ['view'],
                'pos'          => ['view_history'],
                'staff'        => ['view'],
                'customers'    => ['view'],
                'warehouses'   => ['view'],
                'transactions' => ['view', 'export'],
                'invoices'     => ['view'],
                'transfers'    => ['view'],
                'reports'      => ['view_sales', 'view_inventory', 'view_staff', 'view_finance', 'export'],
                'settings'     => ['view'],
                'deliveries'   => ['view'],
                'support'      => ['view_tickets'],
                'coupons'      => ['view'],
            ],
        ],
        'chief_financial_officer' => [
            'name' => 'Chief Financial Officer',
            'description' => 'Financial control: transactions, revenue, payment settings, GL reconciliation, refund approvals.',
            'permissions' => [
                'dashboard'    => ['view'],
                'transactions' => ['view', 'confirm', 'reject', 'refund', 'export'],
                'invoices'     => ['view', 'create', 'edit', 'delete', 'send'],
                'reports'      => ['view_sales', 'view_finance', 'export'],
                'settings'     => ['view', 'payment', 'subscription'],
                'orders'       => ['view', 'refund'],
                'coupons'      => ['view', 'create', 'edit', 'delete'],
                'stores'       => ['view'],
                'customers'    => ['view'],
                'pos'          => ['view_history'],
                'staff'        => ['view'],
                'products'     => ['view'],
                'warehouses'   => ['view'],
                'transfers'    => ['view'],
                'deliveries'   => ['view'],
                'support'      => ['view_tickets'],
            ],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Oversees all store operations, staff, warehouses, orders, and reports.',
            'permissions' => [
                'dashboard'    => ['view'],
                'stores'       => ['view', 'create', 'edit', 'suspend', 'settings'],
                'products'     => ['view', 'create', 'edit', 'delete', 'stock_adjust'],
                'orders'       => ['view', 'status_update', 'refund', 'cancel', 'assign_delivery'],
                'staff'        => ['view', 'create', 'edit', 'suspend', 'activate'],
                'warehouses'   => ['view', 'create', 'edit', 'delete', 'transfer', 'receive', 'approve_transfer'],
                'reports'      => ['view_sales', 'view_inventory', 'view_staff', 'view_finance', 'export'],
                'settings'     => ['view', 'edit', 'payment', 'subscription'],
                'customers'    => ['view', 'edit', 'suspend', 'message'],
                'transactions' => ['view', 'confirm', 'reject', 'refund', 'export'],
                'invoices'     => ['view', 'create', 'edit', 'delete', 'send'],
                'support'      => ['view_tickets', 'reply', 'close'],
                'coupons'      => ['view', 'create', 'edit', 'delete'],
                'deliveries'   => ['view', 'update_status', 'manage_routes'],
                'pos'          => ['view_history'],
                'transfers'    => ['view', 'create', 'approve', 'dispatch', 'receive'],
            ],
        ],
        'store_manager' => [
            'name' => 'Store Manager',
            'description' => 'Manages a store: products, orders, POS, staff, and reports.',
            'permissions' => [
                'dashboard'    => ['view'],
                'products'     => ['view', 'create', 'edit', 'delete', 'stock_adjust'],
                'orders'       => ['view', 'status_update', 'assign_delivery'],
                'pos'          => ['open_session', 'process_sale', 'close_session', 'view_history', 'void_sale'],
                'stores'       => ['view', 'edit', 'settings'],
                'customers'    => ['view', 'message'],
                'reports'      => ['view_sales', 'view_inventory', 'view_staff'],
                'deliveries'   => ['view', 'update_status'],
                'transfers'    => ['view', 'create', 'receive'],
            ],
        ],
        'warehouse_manager' => [
            'name' => 'Warehouse Manager',
            'description' => 'Oversees warehouse operations: receive goods, approve transfers, manage stock.',
            'permissions' => [
                'dashboard'    => ['view'],
                'warehouses'   => ['view', 'create', 'edit', 'transfer', 'receive', 'approve_transfer'],
                'products'     => ['view', 'stock_adjust'],
                'reports'      => ['view_inventory'],
                'deliveries'   => ['view', 'update_status'],
                // 'staff'        => ['view'],
                'transfers'    => ['view', 'create', 'approve', 'dispatch'],
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Handles all financial operations: transactions, reports, payment settings.',
            'permissions' => [
                'dashboard'    => ['view'],
                'transactions' => ['view', 'confirm', 'reject', 'refund', 'export'],
                'reports'      => ['view_sales', 'view_finance', 'export'],
                'settings'     => ['view', 'payment'],
                'orders'       => ['view'],
                'coupons'      => ['view', 'create', 'edit', 'delete'],
                'stores'       => ['view'],
            ],
        ],
        'cashier' => [
            'name' => 'Cashier',
            'description' => 'Processes POS sales, handles walk-in customers, and views products.',
            'permissions' => [
                'products'     => ['view'],
                'pos'          => ['open_session', 'process_sale', 'close_session', 'view_history'],
                'orders'       => ['view', 'create'],
                'customers'    => ['view'],
            ],
        ],
        'inventory_clerk' => [
            'name' => 'Inventory Clerk',
            'description' => 'Manages stock levels, performs stock counts, and executes transfers.',
            'permissions' => [
                'dashboard'    => ['view'],
                'warehouses'   => ['view', 'edit', 'transfer', 'receive'],
                'products'     => ['view', 'stock_adjust'],
                'reports'      => ['view_inventory'],
                'transfers'    => ['view', 'create', 'dispatch', 'receive'],
            ],
        ],
        'customer_support' => [
            'name' => 'Customer Support',
            'description' => 'Handles customer inquiries, support tickets, order issues, and refunds.',
            'permissions' => [
                'dashboard'    => ['view'],
                'orders'       => ['view', 'status_update', 'refund', 'cancel'],
                'customers'    => ['view', 'edit', 'suspend', 'message'],
                'support'      => ['view_tickets', 'reply', 'close'],
                'products'     => ['view'],
                'stores'       => ['view'],
                'reports'      => ['view_sales'],
            ],
        ],
        'delivery_agent' => [
            'name' => 'Delivery Agent',
            'description' => 'Views assigned deliveries and updates delivery status for orders.',
            'permissions' => [
                'dashboard'    => ['view'],
                'deliveries'   => ['view', 'update_status'],
                'orders'       => ['view', 'status_update'],
                'products'     => ['view'],
            ],
        ],
        'developer' => [
            'name' => 'Developer',
            'description' => 'Full technical access for development and debugging.',
            'permissions' => 'all',
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Read-only access to all reports, transactions, and logs for compliance.',
            'permissions' => [
                'dashboard'    => ['view'],
                'warehouses'   => ['view'],
                'products'     => ['view'],
                'orders'       => ['view'],
                'pos'          => ['view_history'],
                'staff'        => ['view'],
                'stores'       => ['view'],
                'customers'    => ['view'],
                'transactions' => ['view', 'export'],
                'reports'      => ['view_sales', 'view_inventory', 'view_staff', 'view_finance', 'export'],
                'settings'     => ['view'],
                'deliveries'   => ['view'],
                'support'      => ['view_tickets'],
                'coupons'      => ['view'],
            ],
        ],
        'store_associate' => [
            'name' => 'Store Associate',
            'description' => 'Helps customers, processes basic POS sales, and views products.',
            'permissions' => [
                'dashboard'    => ['view'],
                'products'     => ['view'],
                'pos'          => ['process_sale', 'view_history'],
                'orders'       => ['view'],
                'customers'    => ['view'],
            ],
        ],
    ];

    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';

        foreach ($this->permissionGroups as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group} {$action}";
                Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
            }
        }

        $allPermissions = Permission::all();
        $permissionsByName = $allPermissions->keyBy('name');

        $platformRole = Role::firstOrCreate(
            ['name' => 'Super Admin', 'business_id' => null, 'guard_name' => $guard]
        );
        $platformRole->syncPermissions($allPermissions);

        $platformAdmins = User::whereNull('business_id')->where('role', 'superadmin')->get();
        foreach ($platformAdmins as $user) {
            $user->assignRole($platformRole);
        }

        $businesses = Business::all();
        foreach ($businesses as $business) {
            $this->createRolesForBusiness($business, $allPermissions, $permissionsByName, $guard);
        }
    }

    public function createRolesForBusiness(Business $business, $allPermissions = null, $permissionsByName = null, string $guard = 'web'): Role
    {
        if ($allPermissions === null) {
            $allPermissions = Permission::all();
        }
        if ($permissionsByName === null) {
            $permissionsByName = $allPermissions->keyBy('name');
        }

        $superAdminRole = null;

        foreach ($this->roles as $slug => $config) {
            $role = Role::firstOrCreate([
                'name' => $config['name'],
                'business_id' => $business->id,
                'guard_name' => $guard,
            ]);

            if ($config['permissions'] === 'all') {
                $role->syncPermissions($allPermissions);
            } else {
                $permIds = [];
                foreach ($config['permissions'] as $group => $actions) {
                    foreach ($actions as $action) {
                        $name = "{$group} {$action}";
                        if (isset($permissionsByName[$name])) {
                            $permIds[] = $permissionsByName[$name]->id;
                        }
                    }
                }
                $role->syncPermissions($permIds);
            }

            if ($slug === 'super_admin') {
                $superAdminRole = $role;
            }
        }

        if ($superAdminRole && $business->owner) {
            setPermissionsTeamId($business->id);
            $business->owner->assignRole($superAdminRole);
        }

        $storeAssociateRole = Role::where('name', 'Store Associate')
            ->where('business_id', $business->id)
            ->first();

        if ($storeAssociateRole) {
            $staff = User::where('business_id', $business->id)
                ->where('role', 'staff')
                ->get();

            foreach ($staff as $user) {
                setPermissionsTeamId($business->id);
                if ($user->roles()->count() === 0) {
                    $user->assignRole($storeAssociateRole);
                }
            }
        }

        return $superAdminRole;
    }
}
