<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-27 — store tabs.
 *
 * The six store-detail tabs (Products, Sales, Transactions, Customers,
 * Invoices, Staff) list their rows through the modules that already own those
 * domains — `products?store_id`, `orders/board/list?store_id`,
 * `transactions?store_id`, `stores/{store}/customers`, `invoices?store_id`
 * and `staff?store_id` — so, per the WS-27 roadmap note, there are no new
 * per-tab list endpoints to register here. Reusing them keeps one payload
 * shape per domain instead of six near-copies drifting apart.
 *
 * What no other endpoint answers is the tab strip itself: how many records
 * each tab holds for this store, and which tabs the caller's role may open.
 * That summary is what this controller adds. Counts are only serialised for
 * tabs the caller holds the permission for — a role that cannot open the
 * Invoices tab is told nothing about its size.
 *
 * Every count is scoped to the store (and its business) exactly the way the
 * list it labels is scoped, including transactions that belong to the store
 * through an invoice rather than an order.
 */
class StoreTabController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Tabs the store workspace renders, in legacy order.
     *
     * The permissions mirror StoreDetailView's tab definitions (WS-03) and the
     * legacy tab bar's gates: products, sales and staff were permission-gated
     * there; transactions, customers and invoices are gated here for the same
     * reason (they were reachable in legacy only because the whole store
     * screen was).
     */
    private const TABS = [
        ['key' => 'products', 'label' => 'Products', 'permission' => 'products view'],
        ['key' => 'sales', 'label' => 'Sales', 'permission' => 'orders view'],
        ['key' => 'transactions', 'label' => 'Transactions', 'permission' => 'transactions view'],
        ['key' => 'customers', 'label' => 'Customers', 'permission' => 'customers view'],
        ['key' => 'invoices', 'label' => 'Invoices', 'permission' => 'invoices view'],
        ['key' => 'staff', 'label' => 'Staff', 'permission' => 'staff view'],
    ];

    /**
     * GET /management/stores/{store}/tabs — the tab strip's counts and the
     * store block the standalone tab workspace draws its header from.
     */
    public function summary(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        // Deleted stores used to leak back into every store read; the store
        // shell refuses them (WS-03) and so does the tab strip behind it.
        if ($store->status === Store::STATUS_DELETED) {
            abort(403, 'This store has been deleted.');
        }

        $user = $this->user($request);

        $tabs = collect(self::TABS)->map(function (array $tab) use ($user, $store) {
            $enabled = $user->can($tab['permission']);

            return [
                'key' => $tab['key'],
                'label' => $tab['label'],
                'permission' => $tab['permission'],
                'enabled' => $enabled,
                'count' => $enabled ? $this->countFor($tab['key'], $store) : null,
            ];
        })->values()->all();

        $currency = $store->currency_id ? Currency::whereKey($store->currency_id)->first(['code', 'symbol']) : null;

        return $this->ok([
            'store' => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
                'has_website' => (bool) $store->has_website,
                'pos_enabled' => (bool) $store->pos_enabled,
                'currency_code' => $currency?->code,
                'currency_symbol' => $currency?->symbol,
            ],
            'tabs' => $tabs,
        ]);
    }

    /**
     * One count per tab, scoped the same way the tab's list endpoint scopes
     * its rows — including the two joins that are easy to get wrong:
     * transactions may hang off an order *or* an invoice, and a store's
     * customers are the people who bought there, not the whole directory.
     */
    private function countFor(string $key, Store $store): int
    {
        return match ($key) {
            'products' => Product::query()
                ->where('business_id', $store->business_id)
                ->where('store_id', $store->id)
                ->count(),

            'sales' => Order::query()
                ->where('business_id', $store->business_id)
                ->where('store_id', $store->id)
                ->count(),

            // Mirrors WS-18's store filter: order- or invoice-borne money
            // only. The legacy tab counted both; a store-only order filter
            // would silently drop invoice payments.
            'transactions' => Transaction::query()
                ->where('business_id', $store->business_id)
                ->where(fn ($scope) => $scope
                    ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                    ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
                ->count(),

            'customers' => Customer::query()
                ->where('business_id', $store->business_id)
                ->whereHas('orders', fn ($orders) => $orders->where('store_id', $store->id))
                ->count(),

            'invoices' => Invoice::query()
                ->where('business_id', $store->business_id)
                ->where('store_id', $store->id)
                ->count(),

            // Mirrors the staff tab's source (WS-20's directory filtered to
            // this store): the owner row plus role=staff, never deactivated
            // accounts.
            'staff' => User::query()
                ->where('business_id', $store->business_id)
                ->whereIn('role', ['staff', User::ROLE_BUSINESS_OWNER])
                ->where('status', '!=', 'deleted')
                ->whereHas('assignedStores', fn ($stores) => $stores->whereKey($store->id))
                ->count(),

            default => 0,
        };
    }
}
