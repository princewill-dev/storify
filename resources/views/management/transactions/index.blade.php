@extends('management.layout')
@section('subtitle', 'Transactions')

@section('content')

<x-management.page-header :breadcrumbs="$breadcrumbs" title="Transactions" subtitle="View payment history across all stores" />

<x-management.data-table>
    <x-slot:search>
        <form method="GET" action="{{ route('management.transactions.index') }}" class="flex flex-wrap items-center gap-2 flex-1">
            <input name="reference" id="transactionsSearch" value="{{ request('reference') }}" placeholder="Search by reference..." autocomplete="off" autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)"
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
            <input type="date" name="date_from" value="{{ request('date_from') }}" placeholder="From" onchange="this.form.submit()"
                class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 w-[140px]">
            <input type="date" name="date_to" value="{{ request('date_to') }}" placeholder="To" onchange="this.form.submit()"
                class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 w-[140px]">
            @if(request()->hasAny(['reference', 'status', 'store_id', 'date_from', 'date_to']))
            <a href="{{ route('management.transactions.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50 whitespace-nowrap">Clear</a>
            @endif
        </form>
    </x-slot:search>
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
            @elseif($tx->invoice)<a href="{{ route('management.invoices.show', $tx->invoice) }}" class="text-sm text-blue-600 hover:text-blue-700">{{ $tx->invoice->invoice_number }}</a>
            @else <span class="text-sm text-slate-400">—</span> @endif
        </td>
        <td class="px-5 py-3 hidden sm:table-cell">
            <span class="text-xs text-slate-500">{{ $tx->order?->store?->name ?? $tx->invoice?->store?->name ?? '—' }}</span>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell">
            <span class="text-sm text-slate-600">{{ $tx->order?->customer?->full_name ?? $tx->invoice?->recipient_name ?? '—' }}</span>
        </td>
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

@endsection

@push('scripts')
<script>
let transactionsTimer;
document.getElementById('transactionsSearch')?.addEventListener('input', function() {
    clearTimeout(transactionsTimer);
    transactionsTimer = setTimeout(() => this.form.submit(), 300);
});
</script>
@endpush
