@extends('management.layout')
@section('subtitle', 'Orders')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Orders" subtitle="Manage customer orders and fulfillment" />

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Customer</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Items</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden xl:table-cell">Handled by</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Date</th>
    </x-slot:header>
    @forelse($orders ?? [] as $order)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.orders.show', $order) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $order->order_number }}</a>
            @if($order->source === 'pos')
            <span class="inline-flex items-center ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-50 text-purple-600">POS</span>
            @endif
        </td>
        <td class="px-5 py-3 hidden lg:table-cell">
            <span class="text-sm text-slate-700">{{ $order->customer?->first_name ? $order->customer->first_name . ' ' . $order->customer->last_name : ($order->meta['customer_name'] ?? 'Walk-in') }}</span>
            @if($order->meta['customer_phone'] ?? false)
            <span class="block text-[11px] text-slate-400">{{ $order->meta['customer_phone'] }}</span>
            @endif
        </td>
        <td class="px-5 py-3 text-center hidden sm:table-cell">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $order->items->count() }}</span>
        </td>
        <td class="px-5 py-3 text-right">
            <span class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span>
            @php $pm = $order->transactions->first()?->paymentMethod; @endphp
            @if($pm)
            <span class="block text-[10px] text-slate-400 uppercase">{{ $pm->code === 'cash' ? 'Cash' : ($pm->code === 'bank_transfer' ? 'Transfer' : $pm->name) }}</span>
            @endif
        </td>
        <td class="px-5 py-3 hidden xl:table-cell">
            @if($order->staff)
            <span class="text-sm text-slate-700">{{ $order->staff->name }}</span>
            @else
            <span class="text-xs text-slate-300">—</span>
            @endif
        </td>
        <td class="px-5 py-3 text-center"><x-management.status-badge :status="$order->status" /></td>
        <td class="px-5 py-3 text-right text-xs text-slate-400 hidden md:table-cell">{{ $order->created_at->format('d M Y') }}</td>
    </tr>
    @empty
    <tr><td colspan="7" class="px-5 py-12">
        <x-management.empty-state icon="fi fi-rr-shopping-cart" title="No orders yet" description="Orders will appear here once customers start purchasing from your stores." />
    </td></tr>
    @endforelse
</x-management.data-table>

<div class="mt-4">
    {{ $orders->links() }}
</div>
@endsection
