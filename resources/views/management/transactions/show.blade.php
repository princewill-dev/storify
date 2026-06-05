@extends('management.layout')
@section('subtitle', $transaction->reference)

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$transaction->reference" subtitle="{{ $transaction->created_at->format('d M Y H:i') }}">
    <x-slot:actions><x-management.status-badge :status="$transaction->status" /></x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <x-management.card header="Transaction Details">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Amount</span>
                    <p class="text-lg font-bold text-slate-900 mt-0.5">₦{{ number_format($transaction->amount, 2) }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Status</span>
                    <x-management.status-badge :status="$transaction->status" class="mt-0.5" />
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Date</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->created_at->format('d M Y, H:i') }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Reference</span>
                    <p class="text-sm font-mono text-slate-700 mt-0.5">{{ $transaction->reference }}</p>
                </div>
            </div>
        </x-management.card>

        @if($transaction->order)
        <x-management.card header="Order">
            <div class="flex items-center justify-between">
                <div>
                    <a href="{{ route('management.orders.show', $transaction->order) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $transaction->order->order_number }}</a>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $transaction->order->items->count() }} items · ₦{{ number_format($transaction->order->total, 2) }}</p>
                </div>
                <x-management.status-badge :status="$transaction->order->status" />
            </div>
        </x-management.card>
        @endif
    </div>

    <div class="space-y-6">
        <x-management.card header="Payment">
            <div class="space-y-3">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Method</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">
                        @php $pm = $transaction->paymentMethod; @endphp
                        @if($pm)
                            @if($pm->code === 'cash') Cash
                            @elseif($pm->code === 'bank_transfer') Bank Transfer
                            @elseif($pm->code === 'paystack') Paystack (Card)
                            @else {{ $pm->name }}
                            @endif
                        @else
                            <span class="text-slate-400">Cash</span>
                        @endif
                    </p>
                </div>
                @if($transaction->gateway_reference)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Gateway Reference</span>
                    <p class="text-sm font-mono font-medium text-slate-700 mt-0.5">{{ $transaction->gateway_reference }}</p>
                </div>
                @endif
                @if($transaction->storeBank)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Bank Account</span>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->storeBank->bank_name }}</p>
                    <p class="text-xs text-slate-500">{{ $transaction->storeBank->account_number }} — {{ $transaction->storeBank->account_name }}</p>
                </div>
                @endif
                @if($transaction->store_balance_before)
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider">Store Balance Before</span>
                    <p class="text-sm font-medium text-slate-700 mt-0.5">₦{{ number_format($transaction->store_balance_before / 100, 2) }}</p>
                </div>
                @endif
            </div>
        </x-management.card>

        @if($transaction->order?->customer || $transaction->order?->meta)
        <x-management.card header="Customer">
            @if($transaction->order->customer && $transaction->order->customer->email !== 'walkin@pos.local')
                <p class="text-sm font-semibold text-slate-800">{{ $transaction->order->customer->first_name }} {{ $transaction->order->customer->last_name }}</p>
                <p class="text-xs text-slate-400">{{ $transaction->order->customer->email }}</p>
            @elseif($transaction->order->meta['customer_name'] ?? false)
                <p class="text-sm font-semibold text-slate-800">{{ $transaction->order->meta['customer_name'] }}</p>
                @if($transaction->order->meta['customer_phone'] ?? false)
                <p class="text-xs text-slate-400">{{ $transaction->order->meta['customer_phone'] }}</p>
                @endif
            @else
                <p class="text-sm text-slate-400">Walk-in customer (POS)</p>
            @endif
        </x-management.card>
        @endif
    </div>
</div>
@endsection
