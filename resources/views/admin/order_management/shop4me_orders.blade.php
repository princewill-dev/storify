@extends('admin.layout')
@section('subtitle', 'Shop4Me Orders')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Shop4Me Orders</h2>
    <button type="button" onclick="openModal('filterModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
        <i class="fi fi-rr-filter text-sm"></i> Filter Orders
    </button>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Total Orders</span>
        <h3 class="text-xl font-bold text-slate-900">{{ number_format($stats['total']) }}</h3>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Pending</span>
        <h3 class="text-xl font-bold text-slate-900">{{ number_format($stats['pending']) }}</h3>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Processing</span>
        <h3 class="text-xl font-bold text-slate-900">{{ number_format($stats['processing']) }}</h3>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Total Revenue</span>
        <h3 class="text-xl font-bold text-slate-900">&#8358;{{ number_format($stats['total_revenue'], 2) }}</h3>
    </div>
</div>

<!-- Active Filters Notice -->
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        @if(request()->hasAny(['search', 'status', 'payment_status', 'store_id', 'date_from', 'date_to']))
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 uppercase">
                <i class="fi fi-rr-filter mr-1 text-xs"></i> Filters Active
            </span>
            <a href="{{ route('admin.shop4me.orders.index') }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">
                <i class="fi fi-rr-cross-small text-xs"></i> Clear Filters
            </a>
        @endif
    </div>
</div>

<!-- Orders Table -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800">Orders ({{ $orders->total() }})</h3>
    </div>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order #</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Items</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Payment</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($orders as $order)
            <tr class="hover:bg-slate-50/50">
                <td class="py-3 px-4">
                    <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-slate-700 hover:text-slate-900">
                        #{{ $order->order_number }}
                    </a>
                </td>
                <td class="py-3 px-4">
                    <div class="text-slate-700">{{ $order->customer?->full_name ?? 'Guest' }}</div>
                    @if($order->customer?->email)
                        <small class="text-slate-400">{{ $order->customer?->email ?? '—' }}</small>
                    @endif
                </td>
                <td class="py-3 px-4 text-slate-700">{{ $order->store?->name ?? '—' }}</td>
                <td class="py-3 px-4 text-slate-600">{{ number_format($order->items_count) }}</td>
                <td class="py-3 px-4 font-semibold text-slate-700">&#8358;{{ number_format($order->total, 2) }}</td>
                <td class="py-3 px-4">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase {{ $order->status->badgeClass() }}" style="font-size: 0.7rem;">
                        {{ $order->status->label() }}
                    </span>
                </td>
                <td class="py-3 px-4">
                    @php($payment = $order->payment_status ?? 'unpaid')
                    @switch($payment)
                        @case('paid')
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Paid</span>
                            @break
                        @case('refunded')
                            <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">Refunded</span>
                            @break
                        @case('failed')
                            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">Failed</span>
                            @break
                        @default
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Unpaid</span>
                    @endswitch
                </td>
                <td class="py-3 px-4 text-xs text-slate-500">{{ optional($order->created_at)->format('M d, Y') }}</td>
                <td class="py-3 px-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('admin.orders.show', $order) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg border border-slate-200 text-slate-400 hover:text-slate-600 hover:bg-slate-50" title="View">
                            <i class="fi fi-rr-eye text-sm"></i>
                        </a>
                        <a href="{{ route('admin.orders.edit', $order) }}" class="inline-flex items-center justify-center p-1.5 rounded-lg border border-slate-200 text-slate-400 hover:text-slate-600 hover:bg-slate-50" title="Edit">
                            <i class="fi fi-rr-pencil text-sm"></i>
                        </a>
                        <button type="button" onclick="openModal('deleteOrder{{ $order->id }}')" class="inline-flex items-center justify-center p-1.5 rounded-lg border border-red-200 text-red-500 hover:bg-red-50" title="Delete">
                            <i class="fi fi-rr-trash text-sm"></i>
                        </button>
                        <x-admin.confirm-modal id="deleteOrder{{ $order->id }}" title="Delete Order" message="Are you sure you want to delete this order?" action="{{ route('admin.orders.destroy', $order) }}" method="DELETE" />
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="py-12 text-center text-slate-400">No Shop4Me orders found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @if($orders->hasPages())
    <div class="px-4 py-3 border-t border-slate-100">
        {{ $orders->links() }}
    </div>
    @endif
</div>

<!-- Filter Modal -->
<div id="filterModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('filterModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6 max-h-[85vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h5 class="text-base font-semibold text-slate-900">Filter Shop4Me Orders</h5>
                <button onclick="closeModal('filterModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
            </div>
            <form method="GET" action="{{ route('admin.shop4me.orders.index') }}" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
                        <input type="text" name="search" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Order # or Customer" value="{{ request('search') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
                        <select name="store_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">All Stores</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Order Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">All Statuses</option>
                            @foreach(['pending','accepted','processing','dispatched','delivered','completed','cancelled','returned'] as $status)
                                <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Payment Status</label>
                        <select name="payment_status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">All</option>
                            @foreach(['unpaid','paid','refunded','failed'] as $payment)
                                <option value="{{ $payment }}" {{ request('payment_status') === $payment ? 'selected' : '' }}>{{ ucfirst($payment) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Date Range</label>
                        <div class="flex items-center gap-2">
                            <input type="date" name="date_from" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ request('date_from') }}">
                            <span class="text-sm text-slate-400">to</span>
                            <input type="date" name="date_to" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ request('date_to') }}">
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                    <a href="{{ route('admin.shop4me.orders.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                        <i class="fi fi-rr-refresh text-sm"></i> Clear All
                    </a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                        <i class="fi fi-rr-search text-sm"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
