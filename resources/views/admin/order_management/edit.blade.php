@extends('admin.layout')
@section('subtitle', 'Edit Order — #' . $order->order_number)

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Edit Order #{{ $order->order_number }}</h2>
    <a href="{{ route('admin.orders.show', $order) }}" class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-50">← Back</a>
</div>

@if($errors->any())
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
    <ul class="list-disc pl-4 space-y-0.5">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<form action="{{ route('admin.orders.update', $order) }}" method="POST">
    @csrf @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-8 gap-6">
        <div class="lg:col-span-5 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200">
                <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-900">Order Items</h3></div>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-50">
                        <tr>
                            <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Product</th>
                            <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Code</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Price</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Qty</th>
                            <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($order->items as $item)
                        <tr class="hover:bg-slate-50/50">
                            <td class="px-5 py-3 text-xs text-slate-700">{{ $item->product_name }}</td>
                            <td class="px-5 py-3 text-xs text-slate-500 font-mono">{{ $item->product_code }}</td>
                            <td class="px-5 py-3 text-right text-xs text-slate-600">₦{{ number_format($item->unit_price, 2) }}</td>
                            <td class="px-5 py-3 text-right text-xs text-slate-600">{{ $item->quantity }}</td>
                            <td class="px-5 py-3 text-right text-xs font-semibold text-slate-800">₦{{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Pricing</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Subtotal</label>
                        <input type="text" value="₦{{ number_format($order->subtotal, 2) }}" readonly class="w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Shipping Fee</label>
                        <input type="number" name="shipping_fee" step="0.01" value="{{ old('shipping_fee', $order->shipping_fee) }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Tax</label>
                        <input type="number" name="tax" step="0.01" value="{{ old('tax', $order->tax) }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Total</label>
                        <input type="text" value="₦{{ number_format($order->total, 2) }}" readonly class="w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Customer Details</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Name</label>
                        <input type="text" name="customer_name" value="{{ old('customer_name', $order->customer?->full_name) }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Phone</label>
                        <input type="text" name="customer_phone" value="{{ old('customer_phone', $order->deliveryAddress?->phone ?? '') }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Delivery Address</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1">Street Address</label>
                        <input type="text" name="delivery_address" value="{{ old('delivery_address', $order->deliveryAddress?->address ?? '') }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">City</label>
                        <input type="text" name="delivery_city" value="{{ old('delivery_city', $order->deliveryAddress?->city ?? '') }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">State</label>
                        <input type="text" name="delivery_state" value="{{ old('delivery_state', $order->deliveryAddress?->state ?? '') }}"
                            class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
            </div>
        </div>

        <div class="lg:col-span-3 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Order Status</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            @foreach(\App\Enums\OrderStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($order->status->value === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Payment Status</label>
                        <select name="payment_status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">— No change —</option>
                            @foreach(\App\Enums\PaymentStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-4">Notes</h3>
                <textarea name="notes" rows="3" class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">{{ old('notes', $order->notes) }}</textarea>
            </div>

            <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">
                Save Changes
            </button>
        </div>
    </div>
</form>
</div>
@endsection
