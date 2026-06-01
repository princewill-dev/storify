@extends('management.layout')
@section('subtitle', 'Orders')

@section('content')
<x-management.page-header title="Orders" subtitle="Manage customer orders and fulfillment">
    <x-slot:actions>
        <a href="{{ route('management.products.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Product
        </a>
    </x-slot:actions>
</x-management.page-header>

<x-management.card>
    <x-management.data-table>
        <x-slot:header>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Store</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Customer</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Items</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
            <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Status</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Date</th>
        </x-slot:header>
        @forelse($orders ?? [] as $order)
        <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3">
                <a href="{{ route('management.orders.show', $order) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $order->order_number }}</a>
            </td>
            <td class="px-5 py-3 hidden md:table-cell"><span class="text-sm text-slate-500">{{ $order->store?->name ?? 'N/A' }}</span></td>
            <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $order->customer?->first_name ?? 'Walk-in' }}</span></td>
            <td class="px-5 py-3 text-center hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $order->items->count() }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span></td>
            <td class="px-5 py-3 text-center hidden md:table-cell"><x-management.status-badge :status="$order->status" /></td>
            <td class="px-5 py-3 text-right hidden lg:table-cell"><span class="text-xs text-slate-400">{{ $order->created_at->format('d M Y') }}</span></td>
        </tr>
        @empty
        <tr><td colspan="7" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-shopping-cart" title="No orders yet" description="Orders will appear here once customers start purchasing from your stores." />
        </td></tr>
        @endforelse
    </x-management.data-table>
</x-management.card>
@endsection
