@extends('management.layout')
@section('subtitle', 'Dispatches')

@section('content')
<div x-data="{ filterModal: false }">

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Dispatches" subtitle="Track deliveries across all stores">
    <x-slot:actions>
        <form method="GET" action="{{ route('management.dispatches.index') }}" class="flex items-center gap-2">
            <div class="flex items-center">
                <input name="search" value="{{ request('search') }}" placeholder="Search driver or order..." class="block w-52 rounded-l-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 border-r-0">
                <button type="submit" class="inline-flex items-center rounded-r-lg bg-blue-600 px-3 py-2 text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-search text-xs"></i></button>
            </div>
            @foreach(request()->except(['search', 'page']) as $k => $v)
                @if($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <button @click="filterModal = true" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                <i class="fi fi-rr-settings-sliders text-xs"></i> Filters
                @php $fc = count(array_filter($activeFilters ?? [], fn($v) => $v !== null && $v !== '')); @endphp
                @if($fc > 0)<span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-600 text-[10px] font-bold text-white">{{ $fc }}</span>@endif
            </button>
            @if(request()->hasAny(['search', 'status', 'store_id', 'date_from', 'date_to']))
            <a href="{{ route('management.dispatches.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Clear</a>
            @endif
        </form>
    </x-slot:actions>
</x-management.page-header>

{{-- Stats --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card :value="$stats['total']" label="Total Dispatches" icon="fi fi-rr-truck-side" />
    <x-management.metric-card :value="$stats['pending']" label="Pending" icon="fi fi-rr-clock" />
    <x-management.metric-card :value="$stats['in_transit']" label="In Transit" icon="fi fi-rr-route" />
    <x-management.metric-card :value="$stats['delivered_today']" label="Delivered Today" icon="fi fi-rr-box-check" />
</div>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Store</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Driver</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Tracking</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">ETA</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Date</th>
    </x-slot:header>
    @forelse($dispatches as $d)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            @if($d->order)
            <a href="{{ route('management.orders.show', $d->order) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $d->order->order_number }}</a>
            @else <span class="text-sm text-slate-400">N/A</span> @endif
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-xs text-slate-500">{{ $d->order?->store?->name ?? '—' }}</span></td>
        <td class="px-5 py-3 hidden md:table-cell">
            @if($d->driver_name)
            <span class="text-sm text-slate-700">{{ $d->driver_name }}</span>
            @if($d->driver_phone)<span class="block text-[11px] text-slate-400">{{ $d->driver_phone }}</span>@endif
            @else <span class="text-xs text-slate-300">—</span> @endif
        </td>
        <td class="px-5 py-3 hidden lg:table-cell">
            @if($d->tracking_number)<span class="text-xs font-mono text-slate-600">{{ $d->tracking_number }}</span>@else<span class="text-xs text-slate-300">—</span>@endif
        </td>
        <td class="px-5 py-3 text-center">
            @php
                $badgeColors = [
                    'pending' => 'bg-slate-100 text-slate-700',
                    'assigned' => 'bg-blue-50 text-blue-700',
                    'picked_up' => 'bg-amber-50 text-amber-700',
                    'in_transit' => 'bg-purple-50 text-purple-700',
                    'out_for_delivery' => 'bg-indigo-50 text-indigo-700',
                    'delivered' => 'bg-emerald-50 text-emerald-700',
                    'failed' => 'bg-red-50 text-red-700',
                    'returned' => 'bg-red-50 text-red-700',
                ];
                $color = $badgeColors[$d->status] ?? 'bg-slate-100 text-slate-700';
            @endphp
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $color }} ring-slate-600/10">{{ ucfirst(str_replace('_', ' ', $d->status)) }}</span>
        </td>
        <td class="px-5 py-3 text-right hidden sm:table-cell">
            @if($d->estimated_delivery_at)<span class="text-xs text-slate-600">{{ \Carbon\Carbon::parse($d->estimated_delivery_at)->format('d M') }}</span>@else<span class="text-xs text-slate-300">—</span>@endif
        </td>
        <td class="px-5 py-3 text-right hidden lg:table-cell"><span class="text-xs text-slate-400">{{ $d->created_at->format('d M Y') }}</span></td>
    </tr>
    @empty
    <tr><td colspan="7" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-truck-side" title="No dispatches yet" description="Dispatches will appear once orders are sent out for delivery." /></td></tr>
    @endforelse
</x-management.data-table>

@if($dispatches->hasPages())
<div class="mt-4 px-5 py-3 bg-white rounded-xl shadow-sm border border-slate-200">
    {{ $dispatches->links() }}
</div>
@endif

{{-- Filter Modal --}}
<div x-show="filterModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
    <div class="flex min-h-screen items-center justify-center px-4 py-8 text-center">
        <div x-show="filterModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50 transition-opacity" @click="filterModal = false" aria-hidden="true"></div>
        <div x-show="filterModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative z-10 inline-block transform overflow-hidden rounded-xl bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:align-middle">
            <div class="bg-white px-6 py-5 border-b border-slate-200">
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fi fi-rr-settings-sliders text-blue-600 text-lg"></i></div>
                    <div><h3 class="text-lg font-semibold text-slate-900">Filter Dispatches</h3><p class="text-sm text-slate-500">Narrow down by status, store, or date.</p></div>
                </div>
            </div>
            <form method="GET" action="{{ route('management.dispatches.index') }}">
                <div class="bg-white px-6 py-4 space-y-4">
                    <div>
                        <label for="f-status" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                        <select id="f-status" name="status" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Statuses</option>
                            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="assigned" {{ request('status') === 'assigned' ? 'selected' : '' }}>Assigned</option>
                            <option value="picked_up" {{ request('status') === 'picked_up' ? 'selected' : '' }}>Picked Up</option>
                            <option value="in_transit" {{ request('status') === 'in_transit' ? 'selected' : '' }}>In Transit</option>
                            <option value="out_for_delivery" {{ request('status') === 'out_for_delivery' ? 'selected' : '' }}>Out for Delivery</option>
                            <option value="delivered" {{ request('status') === 'delivered' ? 'selected' : '' }}>Delivered</option>
                            <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                            <option value="returned" {{ request('status') === 'returned' ? 'selected' : '' }}>Returned</option>
                        </select>
                    </div>
                    <div>
                        <label for="f-store" class="block text-xs font-medium text-slate-600 mb-1">Store</label>
                        <select id="f-store" name="store_id" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Stores</option>
                            @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="f-date-from" class="block text-xs font-medium text-slate-600 mb-1">From</label>
                            <input type="date" id="f-date-from" name="date_from" value="{{ request('date_from') }}" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                        <div>
                            <label for="f-date-to" class="block text-xs font-medium text-slate-600 mb-1">To</label>
                            <input type="date" id="f-date-to" name="date_to" value="{{ request('date_to') }}" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 flex items-center justify-between border-t border-slate-200">
                    <a href="{{ route('management.dispatches.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">Clear all</a>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="filterModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-search text-xs"></i> Apply</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
@endsection
