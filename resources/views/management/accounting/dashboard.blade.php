@extends('management.layout')
@section('subtitle', 'Accounting')

@section('content')

<x-management.page-header title="Accounting" subtitle="Books, journals, expenses, and payables for your business" />

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Assets</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($totals['assets'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Liabilities</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($totals['liabilities'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Income (YTD)</p>
        <p class="text-xl font-bold text-emerald-600 mt-1">₦{{ number_format($totals['income_ytd'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Net Profit (YTD)</p>
        <p class="text-xl font-bold {{ $totals['net_profit_ytd'] >= 0 ? 'text-emerald-600' : 'text-red-600' }} mt-1">₦{{ number_format($totals['net_profit_ytd'] / 100, 2) }}</p>
    </div>
</div>

@if($unpostedCount > 0)
<div class="mb-6 flex items-start gap-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4">
    <i class="fi fi-rr-exclamation text-amber-500 text-lg mt-0.5"></i>
    <div class="flex-1 text-sm">
        <p class="font-semibold">{{ $unpostedCount }} confirmed payment(s) have no ledger entry yet.</p>
        <p class="text-amber-700 mt-0.5">Run <code class="px-1 bg-amber-100 rounded">php artisan ledger:reconcile --post</code> to backfill them.</p>
    </div>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-800">Recent Journal Entries</h2>
            <a href="{{ route('management.accounting.journal.index') }}" class="text-xs text-blue-600 hover:text-blue-700">View all</a>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Entry</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Memo</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($recentEntries as $entry)
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3"><a href="{{ route('management.accounting.journal.show', $entry) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $entry->entry_number }}</a></td>
                    <td class="px-5 py-3 text-slate-500 text-xs">{{ $entry->entry_date->format('d M Y') }}</td>
                    <td class="px-5 py-3 text-slate-600 hidden sm:table-cell truncate max-w-[220px]">{{ $entry->memo ?? '—' }}</td>
                    <td class="px-5 py-3 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($entry->status) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-5 py-10">
                    <x-management.empty-state icon="fi fi-rr-book" title="No journal entries yet" description="Entries are created automatically from sales, payments, and expenses." />
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-800">Cash & Bank Balances</h2>
            </div>
            <div class="divide-y divide-slate-50">
                @foreach($cashAccounts as $account)
                <div class="px-5 py-3 flex items-center justify-between">
                    <span class="text-sm text-slate-600">{{ $account->name }}</span>
                    <span class="text-sm font-semibold text-slate-800">₦{{ number_format(($cashBalances[$account->id] ?? 0) / 100, 2) }}</span>
                </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Quick Actions</h2>
            <div class="space-y-2">
                <a href="{{ route('management.accounting.expenses.create') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-receipt"></i> Record an expense</a>
                <a href="{{ route('management.accounting.bills.create') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-document"></i> Enter a supplier bill</a>
                <a href="{{ route('management.accounting.journal.create') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-edit"></i> Manual journal entry</a>
                <a href="{{ route('management.accounting.accounts.index') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-list"></i> Chart of accounts</a>
                @can('accounting reports')
                <a href="{{ route('management.accounting.reports.index') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-chart-histogram"></i> Financial reports</a>
                @endcan
            </div>
        </div>
    </div>
</div>

@endsection
