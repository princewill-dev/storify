@extends('management.layout')
@section('subtitle', 'Customers')

@section('content')
<div x-data="{ filterModal: false }">

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Customers" subtitle="People who have ordered from your stores">
    <x-slot:actions>
        <form method="GET" action="{{ route('management.customers.index') }}" class="flex items-center gap-2">
            <div class="flex items-center">
                <input name="search" value="{{ request('search') }}" placeholder="Search customers..." class="block w-56 rounded-l-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 border-r-0">
                <button type="submit" class="inline-flex items-center rounded-r-lg bg-blue-600 px-3 py-2 text-white shadow-sm hover:bg-blue-700">
                    <i class="fi fi-rr-search text-xs"></i>
                </button>
            </div>
            @foreach(request()->except(['search', 'page']) as $k => $v)
                @if($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <button @click="filterModal = true" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                <i class="fi fi-rr-settings-sliders text-xs"></i> Filters
                @php $filterCount = count(array_filter($activeFilters ?? [], fn($v) => $v !== null && $v !== '')); @endphp
                @if($filterCount > 0)
                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-600 text-[10px] font-bold text-white">{{ $filterCount }}</span>
                @endif
            </button>
            @if(request()->hasAny(['search', 'status', 'country', 'store_id']))
            <a href="{{ route('management.customers.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Clear</a>
            @endif
        </form>
    </x-slot:actions>
</x-management.page-header>

{{-- Stats --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card :value="$stats['total']" label="Total Customers" icon="fi fi-rr-users-alt" />
    <x-management.metric-card :value="$stats['active']" label="Active" icon="fi fi-rr-user-check" />
    <x-management.metric-card :value="$stats['suspended']" label="Suspended" icon="fi fi-rr-user-slash" />
    <x-management.metric-card :value="$stats['total_orders']" label="Total Orders" icon="fi fi-rr-shopping-cart" />
</div>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Email</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Phone</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Orders</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
    </x-slot:header>

    @forelse($customers as $customer)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.customers.show', $customer) }}" class="flex items-center gap-2.5">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 shrink-0">{{ strtoupper(substr($customer->first_name ?? '?', 0, 1)) }}</span>
                <span class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $customer->first_name }} {{ $customer->last_name }}</span>
            </a>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-500">{{ $customer->email }}</span></td>
        <td class="px-5 py-3 hidden md:table-cell"><span class="text-sm text-slate-500">{{ $customer->phone ?? '—' }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$customer->status" /></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><span class="text-sm font-medium text-slate-600">{{ $customer->orders_count ?? 0 }}</span></td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.customers.show', $customer) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View">
                    <i class="fi fi-rr-eye text-xs"></i>
                </a>
                <a href="{{ route('management.customers.edit', $customer) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </a>
            </div>
        </td>
    </tr>
    @empty
    <tr><td colspan="6" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-users-alt" title="No customers yet" description="Customer data will appear once orders start coming in." /></td></tr>
    @endforelse
</x-management.data-table>

@if($customers->hasPages())
<div class="mt-4 px-5 py-3 bg-white rounded-xl shadow-sm border border-slate-200">
    {{ $customers->links() }}
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
                    <div><h3 class="text-lg font-semibold text-slate-900">Filter Customers</h3><p class="text-sm text-slate-500">Narrow down by status, store, or country.</p></div>
                </div>
            </div>
            <form method="GET" action="{{ route('management.customers.index') }}">
                <div class="bg-white px-6 py-4 space-y-4">
                    {{-- Status --}}
                    <div>
                        <label for="f-status" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                        <select id="f-status" name="status" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Statuses</option>
                            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                        </select>
                    </div>

                    {{-- Store --}}
                    <div>
                        <label for="f-store" class="block text-xs font-medium text-slate-600 mb-1">Store</label>
                        <select id="f-store" name="store_id" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Stores</option>
                            @foreach($stores as $store)
                            <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Country --}}
                    @if($countries->isNotEmpty())
                    <div>
                        <label for="f-country" class="block text-xs font-medium text-slate-600 mb-1">Country</label>
                        <select id="f-country" name="country" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Countries</option>
                            @foreach($countries as $country)
                            <option value="{{ $country }}" {{ request('country') === $country ? 'selected' : '' }}>{{ $country }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                </div>
                <div class="bg-slate-50 px-6 py-4 flex items-center justify-between border-t border-slate-200">
                    <a href="{{ route('management.customers.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">Clear all</a>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="filterModal = false" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><i class="fi fi-rr-search text-xs"></i> Apply Filters</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
@endsection
