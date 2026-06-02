@extends('management.layout')
@section('subtitle', $order->order_number)

@section('content')
<x-management.page-header :title="$order->order_number" subtitle="{{ $order->store?->name ?? 'N/A' }} · {{ $order->created_at->format('d M Y H:i') }}">
    <x-slot:actions>
        <x-management.status-badge :status="$order->status" />
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Order Items --}}
        <x-management.card header="Order Items">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach($order->items as $item)
                <div class="flex items-center justify-between px-5 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ $item->product_name }}</p>
                        <p class="text-xs text-slate-400">Qty: {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }}</p>
                    </div>
                    <span class="text-sm font-semibold text-slate-800">₦{{ number_format($item->subtotal, 2) }}</span>
                </div>
                @endforeach
            </div>
            <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex justify-between rounded-b-xl">
                <span class="text-sm font-semibold text-slate-700">Total</span>
                <span class="text-lg font-bold text-slate-900">₦{{ number_format($order->total, 2) }}</span>
            </div>
        </x-management.card>

        {{-- Transactions --}}
        @if($order->transactions->count() > 0)
        <x-management.card header="Transactions">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach($order->transactions as $tx)
                <div class="flex items-center justify-between px-5 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ $tx->reference }}</p>
                        <p class="text-xs text-slate-400">{{ $tx->created_at->format('d M Y H:i') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-management.status-badge :status="$tx->status" />
                        <span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </x-management.card>
        @endif
    </div>

    {{-- Sidebar --}}
    <div class="space-y-6">
        <x-management.card header="Order Details">
            <div class="space-y-3">
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Source</span><p class="text-sm font-medium text-slate-800 mt-0.5">{{ ucfirst($order->source) }}</p></div>
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Subtotal</span><p class="text-sm font-medium text-slate-800 mt-0.5">₦{{ number_format($order->subtotal, 2) }}</p></div>
                @if($order->shipping_fee)<div><span class="text-xs text-slate-400 uppercase tracking-wider">Shipping</span><p class="text-sm text-slate-600 mt-0.5">₦{{ number_format($order->shipping_fee, 2) }}</p></div>@endif
                @if($order->tax)<div><span class="text-xs text-slate-400 uppercase tracking-wider">Tax</span><p class="text-sm text-slate-600 mt-0.5">₦{{ number_format($order->tax, 2) }}</p></div>@endif
                @if($order->notes)<div><span class="text-xs text-slate-400 uppercase tracking-wider">Notes</span><p class="text-sm text-slate-600 mt-0.5">{{ $order->notes }}</p></div>@endif
            </div>
        </x-management.card>

        <x-management.card header="Customer">
            @if($order->customer)
            <div class="flex items-center gap-3">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600">
                    {{ strtoupper(substr($order->customer->first_name, 0, 1)) }}{{ strtoupper(substr($order->customer->last_name, 0, 1)) }}
                </span>
                <div>
                    <p class="text-sm font-semibold text-slate-800">{{ $order->customer->first_name }} {{ $order->customer->last_name }}</p>
                    <p class="text-xs text-slate-400">{{ $order->customer->email }}</p>
                </div>
            </div>
            @else
            <p class="text-sm text-slate-400">Walk-in customer (POS)</p>
            @endif
        </x-management.card>

        <x-management.card header="Delivery">
            @if($order->deliveryAddress)
                <p class="text-sm text-slate-700">{{ $order->deliveryAddress->full_address ?? $order->deliveryAddress->address }}</p>
            @elseif($order->delivery_state)
                <p class="text-sm text-slate-700">{{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
            @else
                <p class="text-sm text-slate-400">No delivery information</p>
            @endif
        </x-management.card>
    </div>
</div>
@endsection
