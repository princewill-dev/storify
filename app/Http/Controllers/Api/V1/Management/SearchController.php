<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $term = trim((string) $request->string('q'));
        $limit = min(10, max(1, (int) $request->integer('limit', 5)));

        if ($term === '') {
            return $this->ok(['products' => [], 'orders' => [], 'customers' => []]);
        }

        $like = '%'.$term.'%';
        $storeIds = $user->accessibleStoreIds();

        $products = Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('product_code', 'like', $like))
            ->limit($limit)
            ->get(['id', 'product_code', 'name', 'amount', 'store_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'amount' => (float) $product->amount,
                'store_id' => $product->store_id,
            ])->values()->all();

        $orders = Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $storeIds)
            ->where('order_number', 'like', $like)
            ->latest()
            ->limit($limit)
            ->get(['id', 'order_number', 'total', 'status', 'store_id'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                'store_id' => $order->store_id,
            ])->values()->all();

        $customers = Customer::query()
            ->where('business_id', $user->business_id)
            ->where(fn ($q) => $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->limit($limit)
            ->get(['id', 'account_id', 'first_name', 'last_name', 'email', 'phone'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'account_id' => $customer->account_id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])->values()->all();

        return $this->ok([
            'products' => $products,
            'orders' => $orders,
            'customers' => $customers,
        ]);
    }
}
