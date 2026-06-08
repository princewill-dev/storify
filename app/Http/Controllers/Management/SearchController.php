<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['results' => []]);
        }

        $q = trim($request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $cacheKey = 'search:' . $user->business_id . ':' . md5($q);

        $results = Cache::remember($cacheKey, 300, function () use ($user, $q) {
            $results = [];
            $like = '%' . $q . '%';

        // Products
        if ($user->can('products view')) {
            $storeIds = $user->isStaff()
                ? $user->assignedStores()->pluck('stores.id')
                : $user->stores()->pluck('id');

            $products = Product::whereIn('store_id', $storeIds)
                ->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                      ->orWhere('product_code', 'like', $like);
                })
                ->limit(5)
                ->get(['id', 'name', 'product_code', 'store_id']);

            if ($products->isNotEmpty()) {
                $results[] = [
                    'group' => 'Products',
                    'icon' => 'fi-rr-cube',
                    'items' => $products->map(fn($p) => [
                        'title' => $p->name,
                        'subtitle' => $p->product_code,
                        'url' => route('management.products.show', $p),
                    ])->toArray(),
                ];
            }
        }

        // Stores
        if ($user->can('stores view')) {
            $storesQuery = $user->isStaff() ? $user->assignedStores() : $user->stores();
            $stores = (clone $storesQuery)->where('name', 'like', $like)
                ->where('status', '!=', 'deleted')
                ->limit(5)
                ->get(['id', 'name', 'store_id']);

            if ($stores->isNotEmpty()) {
                $results[] = [
                    'group' => 'Stores',
                    'icon' => 'fi-rr-shop',
                    'items' => $stores->map(fn($s) => [
                        'title' => $s->name,
                        'subtitle' => $s->store_id,
                        'url' => route('management.stores.show', $s),
                    ])->toArray(),
                ];
            }
        }

        // Warehouses
        if ($user->can('warehouses view')) {
            $whQuery = $user->isStaff() ? $user->assignedWarehouses() : $user->warehouses();
            $warehouses = (clone $whQuery)->where('name', 'like', $like)
                ->where('status', '!=', 'deleted')
                ->limit(5)
                ->get(['id', 'name', 'warehouse_code']);

            if ($warehouses->isNotEmpty()) {
                $results[] = [
                    'group' => 'Warehouses',
                    'icon' => 'fi-rr-warehouse-alt',
                    'items' => $warehouses->map(fn($w) => [
                        'title' => $w->name,
                        'subtitle' => $w->warehouse_code,
                        'url' => route('management.warehouses.show', $w),
                    ])->toArray(),
                ];
            }
        }

        // Customers — find by business_id OR through orders placed with this user
        if ($user->can('customers view') || $user->isBusinessOwner()) {
            $customers = Customer::where(function ($q) use ($user) {
                    $q->where('business_id', $user->business_id)
                      ->orWhereHas('orders', fn($o) => $o->where('user_id', $user->id));
                })
                ->where(function ($sub) use ($like) {
                    $sub->where('first_name', 'like', $like)
                      ->orWhere('last_name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('account_id', 'like', $like)
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                })
                ->limit(5)
                ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'account_id', 'business_id']);

            if ($customers->isNotEmpty()) {
                $results[] = [
                    'group' => 'Customers',
                    'icon' => 'fi-rr-users-alt',
                    'items' => $customers->map(fn($c) => [
                        'title' => $c->full_name,
                        'subtitle' => $c->email ?? $c->phone ?? $c->account_id ?? '—',
                        'url' => route('management.customers.show', $c),
                    ])->toArray(),
                ];
            }
        }

        // Transactions
        if ($user->can('transactions view')) {
            $transactions = Transaction::where('reference', 'like', $like)
                ->whereHas('order', fn($q) => $q->where('user_id', $user->id))
                ->with('order')
                ->limit(5)
                ->latest()
                ->get();

            if ($transactions->isNotEmpty()) {
                $results[] = [
                    'group' => 'Transactions',
                    'icon' => 'fi-rr-chart-histogram',
                    'items' => $transactions->map(fn($tx) => [
                        'title' => $tx->reference,
                        'subtitle' => '₦' . number_format($tx->amount, 2) . ' · ' . $tx->created_at->format('d M Y'),
                        'url' => route('management.transactions.show', $tx),
                    ])->toArray(),
                ];
            }
        }

        // Staff
        if ($user->can('staff view') || $user->isBusinessOwner()) {
            $staff = User::where('business_id', $user->business_id)
                ->where('status', '!=', 'deleted')
                ->where(function ($sub) use ($like) {
                    $sub->where('name', 'like', $like)
                       ->orWhere('email', 'like', $like)
                       ->orWhere('phone', 'like', $like);
                })
                ->limit(5)
                ->get(['id', 'name', 'email', 'phone', 'role', 'account_code']);

            if ($staff->isNotEmpty()) {
                $results[] = [
                    'group' => 'Staff',
                    'icon' => 'fi-rr-user',
                    'items' => $staff->map(fn($s) => [
                        'title' => $s->name,
                        'subtitle' => $s->email . ' · ' . ucfirst($s->role),
                        'url' => $s->role === 'business_owner'
                            ? null
                            : route('management.staff.show', $s),
                    ])->toArray(),
                ];
            }
        }

            return $results;
        });

        return response()->json(['results' => $results]);
    }
}
