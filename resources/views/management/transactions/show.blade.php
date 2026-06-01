@extends('management.layout')
@section('subtitle', $transaction->reference)

@section('content')
<x-management.page-header :title="$transaction->reference" subtitle="{{ $transaction->created_at->format('d M Y H:i') }}">
    <x-slot:actions><x-management.status-badge :status="$transaction->status" /></x-slot:actions>
</x-management.page-header>

<div>
    <x-management.card header="Transaction Details">
        <div class="grid grid-cols-2 gap-4">
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Reference</span><p class="text-sm font-medium text-slate-800 mt-0.5">{{ $transaction->reference }}</p></div>
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Status</span><x-management.status-badge :status="$transaction->status" class="mt-0.5" /></div>
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Amount</span><p class="text-lg font-bold text-slate-900 mt-0.5">₦{{ number_format($transaction->amount, 2) }}</p></div>
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Store Balance Before</span><p class="text-sm text-slate-600 mt-0.5">₦{{ number_format(($transaction->store_balance_before ?? 0) / 100, 2) }}</p></div>
            <div class="col-span-2"><span class="text-xs text-slate-400 uppercase tracking-wider">Order</span>
                @if($transaction->order)
                <p class="text-sm font-medium mt-0.5"><a href="{{ route('management.orders.show', $transaction->order) }}" class="text-blue-600 hover:text-blue-700">{{ $transaction->order->order_number }}</a></p>
                @else
                <p class="text-sm text-slate-400 mt-0.5">N/A</p>
                @endif
            </div>
        </div>
    </x-management.card>
</div>
@endsection
