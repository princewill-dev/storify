@extends('admin.layout')
@section('subtitle', 'Platform Accounting')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Platform Books</h2>
        <p class="text-sm text-slate-500 mt-0.5">Storify's own ledger — subscription revenue and platform expenses.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.accounting.journal') }}" class="px-4 py-2.5 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Journal</a>
        <a href="{{ route('admin.accounting.reports') }}" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Reports</a>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Assets</p>
        <p class="text-lg font-bold text-slate-900 mt-1">₦{{ number_format($totals['assets'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Liabilities</p>
        <p class="text-lg font-bold text-slate-900 mt-1">₦{{ number_format($totals['liabilities'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Revenue</p>
        <p class="text-lg font-bold text-emerald-600 mt-1">₦{{ number_format($totals['income'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Expenses</p>
        <p class="text-lg font-bold text-red-600 mt-1">₦{{ number_format($totals['expenses'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Net Profit</p>
        <p class="text-lg font-bold {{ $totals['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }} mt-1">₦{{ number_format($totals['net_profit'] / 100, 2) }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Recent Journal Entries</h3>
            <a href="{{ route('admin.accounting.journal') }}" class="text-xs text-blue-600 hover:text-blue-700">View all</a>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 bg-slate-50/50">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Entry</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Date</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Memo</th>
                    <th class="text-center py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($recentEntries as $entry)
                <tr class="hover:bg-slate-50/50">
                    <td class="py-3 px-4"><a href="{{ route('admin.accounting.journal.show', $entry) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $entry->entry_number }}</a></td>
                    <td class="py-3 px-4 text-xs text-slate-500">{{ $entry->entry_date->format('d M Y') }}</td>
                    <td class="py-3 px-4 text-slate-600 truncate max-w-[240px]">{{ $entry->memo ?? '—' }}</td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($entry->status) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-12 text-center text-sm text-slate-400">No platform journal entries yet. Subscription payments post here automatically.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Cash & Clearing</h3></div>
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
            <h3 class="text-sm font-semibold text-slate-800 mb-3">Quick Links</h3>
            <div class="space-y-2">
                <a href="{{ route('admin.accounting.accounts') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-list"></i> Chart of accounts</a>
                <a href="{{ route('admin.accounting.reports', ['report' => 'profit-and-loss']) }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-chart-line-up"></i> Profit & loss</a>
                <a href="{{ route('admin.accounting.reports', ['report' => 'balance-sheet']) }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-scale"></i> Balance sheet</a>
                <a href="{{ route('admin.accounting.settings') }}" class="flex items-center gap-2 text-sm text-slate-600 hover:text-blue-600"><i class="fi fi-rr-settings"></i> Accounting settings</a>
            </div>
        </div>
    </div>
</div>
@endsection
