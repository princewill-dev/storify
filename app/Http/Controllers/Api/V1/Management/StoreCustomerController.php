<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-19 — the store detail "Customers" tab API (legacy
 * `Management\StoreTabController@customers`).
 *
 * The legacy tab listed the distinct customers who had bought from a store,
 * each with a per-store order count and a "unique buyers" metric. The store
 * detail UI is WS-27's; this endpoint is the data it consumes.
 *
 * It also carries the count `StoreController@show` has always emitted as null:
 * the Store model has no `customers` relation, so the payload is the only
 * place that number can come from without editing that controller.
 */
class StoreCustomerController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        if ($request->filled('status')) {
            $request->merge(['status' => strtolower(trim((string) $request->input('status')))]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Customer::query()
            ->where('business_id', $store->business_id)
            ->whereHas('orders', fn ($orders) => $orders->where('store_id', $store->id));

        $customers = (clone $base)
            ->withCount(['orders as orders_count' => fn ($orders) => $orders->where('store_id', $store->id)])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('account_id', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]));
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->ok(
            [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'store_code' => $store->store_id,
                ],
                'customers' => $customers->getCollection()->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'account_id' => $customer->account_id,
                    'name' => $customer->full_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'status' => strtolower($customer->status),
                    'orders_count' => (int) ($customer->orders_count ?? 0),
                    'created_at' => $customer->created_at?->toISOString(),
                ])->values()->all(),
                'customers_count' => (clone $base)->count(),
            ],
            null,
            200,
            $this->paginationMeta($customers),
        );
    }
}
