@extends('management.layout')
@section('subtitle', 'Orders')

@section('content')

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Orders" subtitle="Manage customer orders and fulfillment" />

{{-- Stats --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card :value="$stats['total']" label="Total Orders" icon="fi fi-rr-shopping-cart" />
    <x-management.metric-card :value="$stats['pending']" label="Pending" icon="fi fi-rr-clock" />
    <x-management.metric-card :value="$stats['dispatched']" label="Dispatched" icon="fi fi-rr-truck-side" />
    <x-management.metric-card :value="$stats['delivered']" label="Delivered" icon="fi fi-rr-box-check" />
</div>

<x-management.data-table>
    <x-slot:search>
        <form method="GET" action="{{ route('management.orders.index') }}" class="flex flex-wrap items-center gap-2 flex-1">
            <input name="search" id="ordersSearch" value="{{ request('search') }}" placeholder="Search orders..." autocomplete="off" autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)"
                class="flex-1 min-w-[180px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Statuses</option>
                @foreach($statusOptions as $opt)
                <option value="{{ $opt->value }}" {{ request('status') === $opt->value ? 'selected' : '' }}>{{ $opt->label() }}</option>
                @endforeach
            </select>
            <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Stores</option>
                @foreach($stores as $store)
                <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                @endforeach
            </select>
            <select name="source" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Sources</option>
                <option value="checkout" {{ request('source') === 'checkout' ? 'selected' : '' }}>Online Store</option>
                <option value="pos" {{ request('source') === 'pos' ? 'selected' : '' }}>POS</option>
            </select>
            <input type="date" name="date_from" value="{{ request('date_from') }}" placeholder="From" onchange="this.form.submit()"
                class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 w-[140px]">
            <input type="date" name="date_to" value="{{ request('date_to') }}" placeholder="To" onchange="this.form.submit()"
                class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 w-[140px]">
            @if(request()->hasAny(['search', 'status', 'store_id', 'source', 'date_from', 'date_to']))
            <a href="{{ route('management.orders.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50 whitespace-nowrap">Clear</a>
            @endif
        </form>
    </x-slot:search>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Customer</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Store</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Items</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Date</th>
    </x-slot:header>
    @forelse($orders ?? [] as $order)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.orders.show', $order) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $order->order_number }}</a>
            @if($order->source === 'pos')<span class="inline-flex items-center ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-50 text-purple-600">POS</span>@endif
        </td>
        <td class="px-5 py-3 hidden lg:table-cell">
            <span class="text-sm text-slate-700">{{ $order->customer?->first_name ? $order->customer->first_name . ' ' . $order->customer->last_name : ($order->meta['customer_name'] ?? 'Walk-in') }}</span>
            @if($order->meta['customer_phone'] ?? false)<span class="block text-[11px] text-slate-400">{{ $order->meta['customer_phone'] }}</span>@endif
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-xs text-slate-500">{{ $order->store?->name ?? '—' }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $order->items->count() }}</span></td>
        <td class="px-5 py-3 text-right">
            <span class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span>
            @php $pm = $order->transactions->first()?->paymentMethod; @endphp
            @if($pm)<span class="block text-[10px] text-slate-400 uppercase">{{ $pm->code === 'cash' ? 'Cash' : ($pm->code === 'bank_transfer' ? 'Transfer' : $pm->name) }}</span>@endif
            @if($order->transactions->count() > 1)
            <span class="block text-[10px] font-semibold text-amber-600 uppercase">Split · {{ $order->transactions->count() }} legs</span>
            @endif
        </td>
        <td class="px-5 py-3 text-center">
            <x-management.status-badge :status="$order->status" />
            @if((float) $order->remainingBalance() > 0 && $order->amount_paid > 0)
            <span class="block mt-1 text-[10px] font-semibold text-amber-600">₦{{ number_format($order->remainingBalance(), 2) }} left</span>
            @endif
        </td>
        <td class="px-5 py-3 text-right text-xs text-slate-400 hidden md:table-cell">{{ $order->created_at->format('d M Y') }}</td>
    </tr>
    @empty
    <tr><td colspan="7" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-shopping-cart" title="No orders yet" description="Orders will appear here once customers start purchasing." /></td></tr>
    @endforelse
</x-management.data-table>

@if($orders->hasPages())
<div class="mt-4 px-5 py-3 bg-white rounded-xl shadow-sm border border-slate-200">
    {{ $orders->links() }}
</div>
@endif

@endsection

@push('scripts')
<script>
let ordersTimer;
document.getElementById('ordersSearch')?.addEventListener('input', function() {
    clearTimeout(ordersTimer);
    ordersTimer = setTimeout(() => this.form.submit(), 300);
});
</script>
@endpush
