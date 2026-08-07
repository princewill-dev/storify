<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function search(Request $request, Store $store): JsonResponse
    {
        $q = trim($request->input('q', ''));

        $customers = Customer::query()
            ->whereHas('orders', fn($o) => $o->where('store_id', $store->id))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($x) use ($q) {
                    $x->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%")
                      ->orWhere('account_id', 'like', "%{$q}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$q}%"]);
                });
            })
            ->withCount(['orders' => fn($o) => $o->where('store_id', $store->id)])
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'account_id' => $c->account_id,
                'name' => $c->full_name,
                'phone' => $c->phone,
                'email' => $c->email,
                'orders_count' => $c->orders_count,
                'last_order_at' => $c->orders()->where('store_id', $store->id)->latest()->first()?->created_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => ['customers' => $customers],
        ]);
    }

    public function show(Store $store, $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);

        $recentOrders = $customer->orders()
            ->where('store_id', $store->id)
            ->with(['items', 'transactions.paymentMethod'])
            ->latest()
            ->take(10)
            ->get()
            ->map(fn($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'total' => (float) $o->total,
                'status' => $o->status->value,
                'created_at' => $o->created_at->toISOString(),
                'items_count' => $o->items->count(),
                'items' => $o->items->take(3)->map(fn($i) => [
                    'name' => $i->product_name,
                    'qty' => $i->quantity,
                ]),
            ]);

        $totalSpent = $customer->orders()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->sum('total');

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'account_id' => $customer->account_id,
                    'name' => $customer->full_name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'total_spent' => (float) $totalSpent,
                    'orders_count' => $recentOrders->count(),
                ],
                'orders' => $recentOrders,
            ],
        ]);
    }
}
