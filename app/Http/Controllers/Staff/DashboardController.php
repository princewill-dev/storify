<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasRole('Cashier')) {
            $hasPosStore = $user->assignedStores()->where('pos_enabled', true)->exists();

            if ($hasPosStore) {
                return redirect()->route('staff.pos');
            }
        }

        $hasPosStore = $user->assignedStores()->where('pos_enabled', true)->exists();
        $hasStore = $user->assignedStores()->exists();

        $modules = $this->buildModules($user, $hasPosStore, $hasStore);
        $hasAnyPermission = !empty($modules);

        return view('staff.dashboard', compact('user', 'modules', 'hasAnyPermission'));
    }

    private function buildModules($user, bool $hasPosStore, bool $hasStore): array
    {
        return array_values(array_filter([
            $this->module('pos', 'Point of Sale', 'Process sales at the register, open and close sessions.',
                $user->can('pos process_sale'), $hasPosStore ? route('staff.pos') : null,
                !$hasStore ? 'No store assigned yet — contact your administrator.' : (!$hasPosStore ? 'No POS-enabled store assigned — contact your administrator.' : null)),

            $this->module('stores', 'Store Management', 'Manage your store settings, delivery routes, and payment methods.',
                $user->can('stores view'), route('management.stores.index'),
                !$hasStore ? 'No store assigned yet.' : null),

            $this->module('products', 'Products', 'View and manage product listings, stock levels, and pricing.',
                $user->can('products view'), route('management.products.index')),

            $this->module('orders', 'Orders', 'View and manage customer orders, update status, and process refunds.',
                $user->can('orders view'), route('management.orders.index')),

            $this->module('staff', 'Team', 'View staff members and manage roles.',
                $user->can('staff view'), route('management.staff.index')),

            $this->module('warehouses', 'Warehouses', 'Manage warehouse operations, stock transfers, and inventory.',
                $user->can('warehouses view'), route('management.warehouses.index')),

            $this->module('transactions', 'Transactions', 'View and manage financial transactions, confirm payments.',
                $user->can('transactions view'), route('management.transactions.index')),

            $this->module('customers', 'Customers', 'View customer profiles, order history, and send messages.',
                $user->can('customers view'), route('management.customers.index')),

            $this->module('reports', 'Reports', 'View sales reports, inventory analytics, and staff performance.',
                $user->can('reports view_sales'), route('management.dashboard')),

            $this->module('deliveries', 'Deliveries', 'Manage delivery routes and track order deliveries.',
                $user->can('deliveries view'), route('management.dashboard')),

            $this->module('settings', 'Settings', 'Manage payment settings, subscription plans, and account profile.',
                $user->can('settings view'), route('management.profile.index')),

            $this->module('coupons', 'Coupons', 'Create and manage discount coupons.',
                $user->can('coupons view'), route('management.dashboard')),

            $this->module('support', 'Support', 'View and respond to customer support tickets.',
                $user->can('support view_tickets'), route('management.support-messages.index')),
        ]));
    }

    private function module(string $key, string $label, string $description, bool $can, ?string $route = null, ?string $warning = null): ?array
    {
        if (!$can) {
            return null;
        }

        return compact('key', 'label', 'description', 'route', 'warning');
    }
}
