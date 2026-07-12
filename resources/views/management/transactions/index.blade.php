@extends('management.layout')
@section('subtitle', 'Transactions')

@section('content')
<div x-data="{ filterModal: false }">

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Transactions" subtitle="View payment history across all stores">
    <x-slot:actions>
        <form method="GET" action="{{ route('management.transactions.index') }}" class="flex items-center gap-2">
            <div class="flex items-center">
                <input name="reference" value="{{ request('reference') }}" placeholder="Search by reference..." class="block w-52 rounded-l-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 border-r-0">
                <button type="submit" class="inline-flex items-center rounded-r-lg bg-blue-600 px-3 py-2 text-white shadow-sm hover:bg-blue-700">
                    <i class="fi fi-rr-search text-xs"></i>
                </button>
            </div>
            @foreach(request()->except(['reference', 'page']) as $k => $v)
                @if($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <button @click="filterModal = true" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                <i class="fi fi-rr-settings-sliders text-xs"></i> Filters
                @php $filterCount = count(array_filter($activeFilters ?? [], fn($v) => $v !== null && $v !== '')); @endphp
                @if($filterCount > 0)
                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-600 text-[10px] font-bold text-white">{{ $filterCount }}</span>
                @endif
            </button>
            @if(request()->hasAny(['reference', 'status', 'store_id', 'date_from', 'date_to']))
            <a href="{{ route('management.transactions.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Clear</a>
            @endif
        </form>
    </x-slot:actions>
</x-management.page-header>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Order</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Store</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Customer</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Date</th>
    </x-slot:header>

    @forelse($transactions ?? [] as $tx)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3"><a href="{{ route('management.transactions.show', $tx) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $tx->reference }}</a></td>
        <td class="px-5 py-3 hidden md:table-cell">
            @if($tx->order)<a href="{{ route('management.orders.show', $tx->order) }}" class="text-sm text-blue-600 hover:text-blue-700">{{ $tx->order->order_number }}</a>
            @else <span class="text-sm text-slate-400">N/A</span> @endif
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-xs text-slate-500">{{ $tx->order?->store?->name ?? '—' }}</span></td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $tx->order?->customer?->first_name ?? 'N/A' }}</span></td>
        <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$tx->status" /></td>
        <td class="px-5 py-3 text-right hidden lg:table-cell"><span class="text-xs text-slate-400">{{ $tx->created_at->format('d M Y') }}</span></td>
    </tr>
    @empty
    <tr><td colspan="7" class="px-5 py-12">
        <x-management.empty-state icon="fi fi-rr-file-invoice-dollar" title="No transactions yet" description="Transactions will appear here once payments are processed." />
    </td></tr>
    @endforelse
</x-management.data-table>

@if($transactions->hasPages())
<div class="mt-4 px-5 py-3 bg-white rounded-xl shadow-sm border border-slate-200">
    {{ $transactions->links() }}
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
                    <div><h3 class="text-lg font-semibold text-slate-900">Filter Transactions</h3><p class="text-sm text-slate-500">Narrow down by status, store, or date range.</p></div>
                </div>
            </div>
            <form method="GET" action="{{ route('management.transactions.index') }}">
                <div class="bg-white px-6 py-4 space-y-4">
                    {{-- Status --}}
                    <div>
                        <label for="f-status" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                        <select id="f-status" name="status" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                            <option value="">All Statuses</option>
                            @foreach($statusOptions as $opt)
                            <option value="{{ $opt->value }}" {{ request('status') === $opt->value ? 'selected' : '' }}>{{ $opt->label() }}</option>
                            @endforeach
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

                    {{-- Date Range --}}
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
                    <a href="{{ route('management.transactions.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">Clear all</a>
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
