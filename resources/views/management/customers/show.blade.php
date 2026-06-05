@extends('management.layout')
@section('subtitle', $customer->first_name . ' ' . $customer->last_name)

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$customer->first_name . ' ' . $customer->last_name" subtitle="Customer since {{ $customer->created_at->format('d M Y') }}">
    <x-slot:actions><x-management.status-badge :status="$customer->status" /></x-slot:actions>
</x-management.page-header>

<div>
    <x-management.card header="Customer Information">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Email</span><p class="text-sm text-slate-800 mt-0.5">{{ $customer->email }}</p></div>
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Phone</span><p class="text-sm text-slate-800 mt-0.5">{{ $customer->phone ?? '—' }}</p></div>
            <div><span class="text-xs text-slate-400 uppercase tracking-wider">Account ID</span><p class="text-sm text-slate-600 mt-0.5 font-mono">{{ $customer->account_id }}</p></div>
        </div>
    </x-management.card>

    <div class="mt-6">
        <x-management.card header="Recent Orders">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($customer->orders as $ord)
                <a href="{{ route('management.orders.show', $ord) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                    <div class="min-w-0">
                        <span class="text-sm font-medium text-slate-800">{{ $ord->order_number }}</span>
                        <p class="text-xs text-slate-400">{{ $ord->created_at->format('d M Y') }}</p>
                    </div>
                    <div class="flex items-center gap-2"><x-management.status-badge :status="$ord->status" /><span class="text-sm font-semibold text-slate-800">₦{{ number_format($ord->total, 2) }}</span></div>
                </a>
                @empty
                <div class="px-5 py-6 text-center text-sm text-slate-400">No orders</div>
                @endforelse
            </div>
        </x-management.card>
    </div>
</div>
@endsection
