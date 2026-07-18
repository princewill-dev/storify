@extends('management.layout')
@section('subtitle', 'Customers')

@section('content')

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Customers" subtitle="People who have ordered from your stores" />

{{-- Stats --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card :value="$stats['total']" label="Total Customers" icon="fi fi-rr-users-alt" />
    <x-management.metric-card :value="$stats['active']" label="Active" icon="fi fi-rr-user-check" />
    <x-management.metric-card :value="$stats['suspended']" label="Suspended" icon="fi fi-rr-user-slash" />
    <x-management.metric-card :value="$stats['total_orders']" label="Total Orders" icon="fi fi-rr-shopping-cart" />
</div>

<x-management.data-table>
    <x-slot:search>
        <form method="GET" action="{{ route('management.customers.index') }}" class="flex flex-wrap items-center gap-2 flex-1">
            <input name="search" id="customersSearch" value="{{ request('search') }}" placeholder="Search customers..." autocomplete="off" autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)"
                class="flex-1 min-w-[180px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>
            <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Stores</option>
                @foreach($stores as $store)
                <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                @endforeach
            </select>
            @if($countries->isNotEmpty())
            <select name="country" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Countries</option>
                @foreach($countries as $country)
                <option value="{{ $country }}" {{ request('country') === $country ? 'selected' : '' }}>{{ $country }}</option>
                @endforeach
            </select>
            @endif
            @if(request()->hasAny(['search', 'status', 'store_id', 'country']))
            <a href="{{ route('management.customers.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50 whitespace-nowrap">Clear</a>
            @endif
        </form>
    </x-slot:search>
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

@endsection

@push('scripts')
<script>
let customersTimer;
document.getElementById('customersSearch')?.addEventListener('input', function() {
    clearTimeout(customersTimer);
    customersTimer = setTimeout(() => this.form.submit(), 300);
});
</script>
@endpush
