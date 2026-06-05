@extends('management.layout')
@section('subtitle', 'Transactions')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Transactions" subtitle="View payment history across all stores" />

<x-management.data-table>
        <x-slot:header>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Order</th>
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
            <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $tx->order?->customer?->first_name ?? 'N/A' }}</span></td>
            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span></td>
            <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$tx->status" /></td>
            <td class="px-5 py-3 text-right hidden lg:table-cell"><span class="text-xs text-slate-400">{{ $tx->created_at->format('d M Y') }}</span></td>
        </tr>
        @empty
        <tr><td colspan="6" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-file-invoice-dollar" title="No transactions yet" description="Transactions will appear here once payments are processed." />
        </td></tr>
        @endforelse
    </x-management.data-table>
@endsection
