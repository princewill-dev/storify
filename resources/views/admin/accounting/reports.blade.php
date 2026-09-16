@extends('admin.layout')
@section('subtitle', 'Platform Reports')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Platform Financial Reports</h2>
        <p class="text-sm text-slate-500 mt-0.5">Statements for Storify's own books.</p>
    </div>
    <a href="{{ route('admin.accounting.index') }}" class="px-4 py-2.5 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Back</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Report</label>
            <select name="report" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
                <option value="profit-and-loss" @selected($report === 'profit-and-loss')>Profit & Loss</option>
                <option value="balance-sheet" @selected($report === 'balance-sheet')>Balance Sheet</option>
                <option value="trial-balance" @selected($report === 'trial-balance')>Trial Balance</option>
            </select>
        </div>
        @if($report === 'balance-sheet')
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">As of</label>
            <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
        </div>
        @else
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
        </div>
        @endif
        <button class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Run</button>
    </form>
</div>

@if($report === 'profit-and-loss' && isset($profitAndLoss))
@php $pl = $profitAndLoss; @endphp
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Income</p>
        <p class="text-lg font-bold text-emerald-600 mt-1">₦{{ number_format($pl['total_income'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Expenses</p>
        <p class="text-lg font-bold text-red-600 mt-1">₦{{ number_format($pl['total_expenses'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Net Profit</p>
        <p class="text-lg font-bold {{ $pl['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }} mt-1">₦{{ number_format($pl['net_profit'] / 100, 2) }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Income</h3></div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-50">
                @forelse($pl['income'] as $line)
                <tr><td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                @empty
                <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No income.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Expenses</h3></div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-50">
                @forelse($pl['expenses'] as $line)
                <tr><td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                @empty
                <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No expenses.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

@if($report === 'balance-sheet' && isset($balanceSheet))
@php $bs = $balanceSheet; @endphp
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Assets</h3></div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-50">
                @forelse($bs['assets'] as $line)
                <tr><td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                @empty
                <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No assets.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="border-t border-slate-100 bg-slate-50/50">
                <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Assets</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($bs['total_assets'] / 100, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Liabilities</h3></div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @forelse($bs['liabilities'] as $line)
                    <tr><td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                    @empty
                    <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No liabilities.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t border-slate-100 bg-slate-50/50">
                    <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Liabilities</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($bs['total_liabilities'] / 100, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Equity</h3></div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @foreach($bs['equity'] as $line)
                    <tr><td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td></tr>
                    @endforeach
                    <tr><td class="px-5 py-3 text-slate-600">Current Period Earnings</td><td class="px-5 py-3 text-right font-semibold {{ $bs['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">₦{{ number_format($bs['net_profit'] / 100, 2) }}</td></tr>
                </tbody>
                <tfoot class="border-t border-slate-100 bg-slate-50/50">
                    <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Equity</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($bs['total_equity'] / 100, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endif

@if($report === 'trial-balance' && isset($trialBalance))
@php $tb = $trialBalance; @endphp
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100 bg-slate-50/50">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Code</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Account</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Debit</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Credit</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($tb['lines'] as $line)
            <tr>
                <td class="py-3 px-4 font-mono text-xs text-slate-400">{{ $line['account']->code }}</td>
                <td class="py-3 px-4 text-slate-700">{{ $line['account']->name }}</td>
                <td class="py-3 px-4 text-right {{ $line['debit'] > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' }}">{{ $line['debit'] > 0 ? '₦'.number_format($line['debit'] / 100, 2) : '—' }}</td>
                <td class="py-3 px-4 text-right {{ $line['credit'] > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' }}">{{ $line['credit'] > 0 ? '₦'.number_format($line['credit'] / 100, 2) : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="py-12 text-center text-sm text-slate-400">No entries in this period.</td></tr>
            @endforelse
        </tbody>
        @if(count($tb['lines']) > 0)
        <tfoot class="border-t border-slate-200 bg-slate-50/50">
            <tr>
                <td colspan="2" class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                <td class="py-3 px-4 text-right font-bold text-slate-900">₦{{ number_format($tb['total_debit'] / 100, 2) }}</td>
                <td class="py-3 px-4 text-right font-bold text-slate-900">₦{{ number_format($tb['total_credit'] / 100, 2) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
@endif
@endsection
