<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->where('business_id', $this->user($request)->business_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', strtoupper($request->string('status'))))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount('orders')
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $customers->getCollection()->map(fn (Customer $customer) => $this->payload($customer))->values()->all(),
            null,
            200,
            $this->paginationMeta($customers)
        );
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $stats = [
            'total_orders' => $customer->orders()->count(),
            'completed_orders' => $customer->orders()->where('status', 'completed')->count(),
            'total_spent' => (float) $customer->orders()->where('status', 'completed')->sum('total'),
        ];

        $recentOrders = $customer->orders()->latest()->limit(10)->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'status' => $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all();

        return $this->ok([
            'customer' => $this->payload($customer),
            'stats' => $stats,
            'recent_orders' => $recentOrders,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:190'],
        ]);

        $customer->update($data);

        return $this->ok(['customer' => $this->payload($customer->fresh())], 'Customer updated.');
    }

    public function suspend(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $customer->update(['status' => Customer::STATUS_SUSPENDED, 'email_verified_at' => null]);

        return $this->ok(['customer' => $this->payload($customer->fresh())], 'Customer suspended.');
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $customer->update([
            'status' => Customer::STATUS_ACTIVE,
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ]);

        return $this->ok(['customer' => $this->payload($customer->fresh())], 'Customer activated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'name' => $customer->full_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => strtolower($customer->status),
            'orders_count' => $customer->orders_count ?? null,
            'created_at' => $customer->created_at?->toISOString(),
        ];
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        if ((int) $customer->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this customer.');
        }
    }
}
