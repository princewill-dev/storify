<?php

namespace App\Http\Controllers\Management;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class StoreTabController extends Controller
{
    public function __construct(private readonly StoreAccessService $access) {}

    public function show(Request $request, Store $store, string $tab): View
    {
        $this->access->authorize($request->user(), $store);

        return match ($tab) {
            'products' => $this->products($request, $store),
            'orders' => $this->orders($request, $store),
            'settings' => $this->settings($request, $store),
            'staff' => $this->staff($request, $store),
            'transactions' => $this->transactions($request, $store),
            'customers' => $this->customers($request, $store),
            'invoices' => $this->invoices($request, $store),
            'web-metrics' => $this->webMetrics($store),
            default => abort(404, 'Unknown tab'),
        };
    }

    private function products(Request $request, Store $store): View
    {
        $query = Product::query()
            ->where('store_id', $store->id)
            ->with(['category', 'store', 'images', 'section.warehouse'])
            ->withMin('variants', 'amount')
            ->withMax('variants', 'amount');
        $this->applySearchAndStatus($query, $request, 'product_code');
        $products = $query->latest()->paginate($request->integer('per_page', 10))->withQueryString();
        $currencies = Currency::query()->get(['id', 'symbol'])->keyBy('id');
        $displayPrices = $products->mapWithKeys(function (Product $product) use ($currencies) {
            $symbol = $currencies->get($product->currency_id)?->symbol ?? '';
            $min = $product->has_variants && $product->variants_min_amount !== null
                ? (float) $product->variants_min_amount
                : (float) $product->amount;
            $max = $product->has_variants ? (float) ($product->variants_max_amount ?? $min) : $min;
            $price = $symbol.number_format($min, 2);

            return [$product->id => $min === $max ? $price : $price.' - '.$symbol.number_format($max, 2)];
        })->all();
        $user = $request->user();

        return view('management.stores.tabs.products', compact('user', 'store', 'products', 'displayPrices'));
    }

    private function orders(Request $request, Store $store): View
    {
        $query = Order::query()->where('store_id', $store->id)->with(['customer', 'store', 'items', 'staff']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($nested) => $nested
                ->where('order_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customers) => $customers
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")));
        }
        $orders = $query->latest()->paginate(15)->withQueryString();
        $user = $request->user();

        return view('management.stores.tabs.orders', compact('user', 'store', 'orders'));
    }

    private function settings(Request $request, Store $store): View
    {
        $user = $request->user();
        $store->load(['ownershipType', 'businessType', 'business', 'deliveryRoutes', 'assignedStaff.roles', 'paymentMethods', 'assignedBanks', 'serviceCharges']);
        $availableStaff = User::query()
            ->where('business_id', $user->business_id)->where('role', 'staff')->where('status', 'active')
            ->whereNotIn('id', $store->assignedStaff->pluck('id'))->with('roles')->get(['id', 'name', 'email']);
        $assignedMethodIds = $store->paymentMethods->pluck('id');
        $availableMethods = DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $user->business_id)
            ->where('business_payment_method.is_active', true)
            ->whereNotIn('payment_methods.id', $assignedMethodIds)
            ->select('business_payment_method.id', 'payment_methods.name', 'payment_methods.code', 'payment_methods.type')->get()
            ->map(fn ($method) => (array) $method);
        $assignedMethodPivotIds = DB::table('business_payment_method')
            ->join('store_payment_method', 'store_payment_method.payment_method_id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $user->business_id)
            ->where('store_payment_method.store_id', $store->id)
            ->where('store_payment_method.is_active', true)
            ->pluck('business_payment_method.id', 'business_payment_method.payment_method_id');
        $gatewayConfigs = DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $user->business_id)
            ->where('payment_methods.type', 'gateway')->where('business_payment_method.is_active', true)
            ->select('business_payment_method.id', 'business_payment_method.config', 'payment_methods.name')->get()
            ->mapWithKeys(fn ($gateway) => [$gateway->id => ['name' => $gateway->name, 'config' => json_decode($gateway->config, true)]]);
        $bankAccounts = StoreBank::where('business_id', $user->business_id)->get();
        $availableBankAccounts = $bankAccounts->whereNotIn('id', $store->assignedBanks->pluck('id'));

        return view('management.stores.tabs.settings', compact(
            'user', 'store', 'availableStaff', 'availableMethods', 'assignedMethodPivotIds',
            'gatewayConfigs', 'bankAccounts', 'availableBankAccounts'
        ));
    }

    private function staff(Request $request, Store $store): View
    {
        $user = $request->user();
        $staff = User::query()->where('business_id', $user->business_id)
            ->whereIn('role', ['staff', 'business_owner'])->where('status', '!=', 'deleted')
            ->whereHas('assignedStores', fn ($stores) => $stores->where('assignmentable_id', $store->id))
            ->with('roles', 'assignedStores', 'assignedWarehouses')->latest()->get();

        return view('management.stores.tabs.staff', compact('user', 'store', 'staff'));
    }

    private function transactions(Request $request, Store $store): View
    {
        $query = Transaction::query()
            ->where(fn ($nested) => $nested
                ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
            ->with(['order.customer', 'invoice.store', 'paymentMethod']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $transactions = $query->latest()->paginate(15)->withQueryString();
        $user = $request->user();

        return view('management.stores.tabs.transactions', compact('user', 'store', 'transactions'));
    }

    private function customers(Request $request, Store $store): View
    {
        $customers = Customer::query()
            ->where('business_id', $store->business_id)
            ->whereHas('orders', fn ($orders) => $orders->where('store_id', $store->id))
            ->withCount(['orders as orders_count' => fn ($orders) => $orders->where('store_id', $store->id)])
            ->latest()->paginate(15)->withQueryString();
        $user = $request->user();

        return view('management.stores.tabs.customers', compact('user', 'store', 'customers'));
    }

    private function invoices(Request $request, Store $store): View
    {
        $query = Invoice::query()->where('store_id', $store->id)->with(['customer', 'items']);
        if (in_array($request->string('status')->toString(), array_column(InvoiceStatus::cases(), 'value'), true)) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(fn ($nested) => $nested
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('recipient_name', 'like', "%{$search}%")
                ->orWhere('recipient_email', 'like', "%{$search}%"));
        }
        $invoices = $query->latest()->paginate(15)->withQueryString();
        $user = $request->user();

        return view('management.stores.tabs.invoices', compact('user', 'store', 'invoices'));
    }

    private function webMetrics(Store $store): View
    {
        $storeViews = $store->views;
        $productViews = Product::where('store_id', $store->id)->sum('views');
        $webOrders = Order::where('store_id', $store->id)->where('source', 'checkout')->count();
        $webRevenue = (int) Transaction::query()->where('status', 'confirmed')
            ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id)->where('source', 'checkout'))->sum('amount');
        $topProducts = Product::where('store_id', $store->id)->orderByDesc('views')->take(10)->get();
        $monthlyData = Order::where('store_id', $store->id)
            ->where('source', 'checkout')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') month, COUNT(*) count")
            ->groupBy('month')->get()->keyBy('month');
        $monthlyWebOrders = [];
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->subMonths($monthsAgo);
            $monthlyWebOrders[] = [
                'month' => $month->format('M'),
                'count' => (int) ($monthlyData->get($month->format('Y-m'))?->count ?? 0),
            ];
        }

        return view('management.stores.tabs.web-metrics', compact(
            'store', 'storeViews', 'productViews', 'webOrders', 'webRevenue', 'topProducts', 'monthlyWebOrders'
        ));
    }

    private function applySearchAndStatus($query, Request $request, string $codeColumn, bool $status = true): void
    {
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere($codeColumn, 'like', "%{$search}%"));
        }
        if ($status && in_array($request->string('status')->toString(), ['active', 'inactive'], true)) {
            $query->where('status', $request->string('status'));
        }
    }
}
