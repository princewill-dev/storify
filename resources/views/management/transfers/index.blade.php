@extends('management.layout')
@section('subtitle', 'Stock Transfers')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Stock Transfers" subtitle="Manage inventory movement between warehouses and stores">
    <x-slot:actions>
        @can('transfers create')
        <a href="{{ route('management.transfers.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> New Transfer
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

{{-- Status Filter Tabs --}}
<div class="flex items-center gap-1 mb-6 border-b border-slate-200 overflow-x-auto">
    <a href="{{ route('management.transfers.index') }}" class="px-3 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap {{ !$status ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }} -mb-px transition-colors">All</a>
    @foreach($statuses as $st)
    <a href="{{ route('management.transfers.index', ['status' => $st->value]) }}" class="px-3 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap {{ $status === $st->value ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }} -mb-px transition-colors">{{ $st->label() }}</a>
    @endforeach
</div>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Transfer</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">From</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">To</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Items</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Date</th>
    </x-slot:header>

    @forelse($transfers as $transfer)
    <tr class="hover:bg-slate-50/50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.transfers.show', $transfer) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $transfer->transfer_code }}</a>
            <p class="text-xs text-slate-400">{{ $transfer->requester?->name }}</p>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell">
            <span class="text-sm text-slate-700">{{ $transfer->fromLocation?->name ?? '—' }}</span>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell">
            <span class="text-sm text-slate-700">{{ $transfer->toLocation?->name ?? '—' }}</span>
        </td>
        <td class="px-5 py-3 text-center">
            <span class="text-sm font-medium text-slate-600">{{ $transfer->items->count() }}</span>
        </td>
        <td class="px-5 py-3 text-center">
            <x-management.status-badge :status="$transfer->status->value" />
        </td>
        <td class="px-5 py-3 text-right hidden md:table-cell">
            <span class="text-xs text-slate-400">{{ $transfer->created_at->format('d M Y') }}</span>
        </td>
    </tr>
    @empty
    <tr>
        <td colspan="6" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-exchange" title="No transfers yet" description="Stock transfers let you move inventory between your warehouse and stores." action-label="New Transfer" action-url="{{ route('management.transfers.create') }}" />
        </td>
    </tr>
    @endforelse
</x-management.data-table>
@endsection
