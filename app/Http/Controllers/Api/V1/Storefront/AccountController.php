<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends ApiController
{
    public function orders(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['store:id,name,slug'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return $this->ok(
            $orders->getCollection()->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'store' => $order->store?->name,
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'status' => $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all(),
            null,
            200,
            [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        );
    }

    public function showOrder(Request $request, string $orderNumber): JsonResponse
    {
        $customer = $this->customer($request);

        $order = Order::where('order_number', $orderNumber)
            ->where('customer_id', $customer->id)
            ->with(['items', 'store:id,name,slug', 'transactions'])
            ->firstOrFail();

        return $this->ok([
            'order' => [
                'order_number' => $order->order_number,
                'store' => $order->store?->name,
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'remaining' => (float) $order->remainingBalance(),
                'status' => $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status,
                'created_at' => $order->created_at?->toISOString(),
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'is_digital' => (bool) $item->is_digital,
                ])->values()->all(),
                'transactions' => $order->transactions->map(fn ($transaction) => [
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status instanceof \App\Enums\TransactionStatus ? $transaction->status->value : $transaction->status,
                ])->values()->all(),
            ],
            'downloads' => $order->isFullyPaid()
                ? $order->digitalDownloads()->with('product')->get()
                    ->filter(fn ($download) => $download->product !== null)
                    ->map(fn ($download) => [
                        'product_name' => $download->product->name,
                        'token' => $download->token,
                        'downloads_remaining' => $download->downloadsRemaining(),
                        'expires_at' => $download->expires_at?->toISOString(),
                        'status' => $download->status_label,
                    ])->values()->all()
                : [],
        ]);
    }

    public function downloads(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $downloads = \App\Models\DigitalDownload::query()
            ->where('customer_id', $customer->id)
            ->with(['product:id,name'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return $this->ok(
            $downloads->getCollection()->map(fn ($download) => [
                'product_name' => $download->product?->name,
                'token' => $download->token,
                'downloads_remaining' => $download->downloadsRemaining(),
                'max_downloads' => (int) $download->max_downloads,
                'expires_at' => $download->expires_at?->toISOString(),
                'status' => $download->status_label,
                'is_active' => $download->isActive(),
            ])->values()->all(),
            null,
            200,
            [
                'current_page' => $downloads->currentPage(),
                'last_page' => $downloads->lastPage(),
                'per_page' => $downloads->perPage(),
                'total' => $downloads->total(),
            ]
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:190'],
            'state' => ['nullable', 'string', 'max:190'],
            'country' => ['nullable', 'string', 'max:190'],
        ]);

        $customer->update($data);

        return $this->ok(['user' => [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ]], 'Profile updated.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}
