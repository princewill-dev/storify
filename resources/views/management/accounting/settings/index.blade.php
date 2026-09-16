@extends('management.layout')
@section('subtitle', 'Accounting Settings')

@section('content')

<x-management.page-header title="Accounting Settings" subtitle="Account mappings, fiscal periods, and opening balances" />

@php
    $mappingLabels = [
        'cash' => 'Cash on Hand',
        'bank' => 'Bank',
        'gateway_clearing' => 'Payment Gateway Clearing',
        'accounts_receivable' => 'Accounts Receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed Assets',
        'accounts_payable' => 'Accounts Payable',
        'tax_payable' => 'VAT Payable',
        'accrued_liabilities' => 'Accrued Liabilities',
        'owner_equity' => "Owner's Equity",
        'retained_earnings' => 'Retained Earnings',
        'opening_balance_equity' => 'Opening Balance Equity',
        'sales_income' => 'Sales Revenue',
        'service_charge_income' => 'Service Charge Income',
        'shipping_income' => 'Shipping Income',
        'other_income' => 'Other Income',
        'sales_discounts' => 'Sales Discounts',
        'cogs' => 'Cost of Goods Sold',
        'gateway_fees' => 'Bank & Gateway Fees',
        'default_expense' => 'Miscellaneous Expense',
    ];
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="space-y-6">
        @can('accounting settings')
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-800">Auto-Posting Accounts</h2>
                <p class="text-xs text-slate-400 mt-0.5">Which account each money event posts to.</p>
            </div>
            <form method="POST" action="{{ route('management.accounting.settings.mappings.update') }}">
                @csrf @method('PUT')
                <div class="divide-y divide-slate-50 max-h-[560px] overflow-y-auto">
                    @foreach($mappingLabels as $key => $label)
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                        <span class="text-sm text-slate-600">{{ $label }}</span>
                        <select name="mappings[{{ $key }}]" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm w-56">
                            @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected(($mappings[$key]->ledger_account_id ?? null) == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endforeach
                </div>
                <div class="px-5 py-4 border-t border-slate-100">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Mappings</button>
                </div>
            </form>
        </div>
        @endcan

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-800">Opening Balances</h2>
                <p class="text-xs text-slate-400 mt-0.5">One-time entry when you start using the ledger.</p>
            </div>
            @if($openingEntry)
            <div class="p-5">
                <div class="flex items-start gap-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg p-4">
                    <i class="fi fi-rr-check-circle text-emerald-500 mt-0.5"></i>
                    <div class="text-sm">
                        <p class="font-semibold">Opening balances posted</p>
                        <p class="text-emerald-700 mt-0.5">Entry <a href="{{ route('management.accounting.journal.show', $openingEntry) }}" class="underline">{{ $openingEntry->entry_number }}</a> on {{ $openingEntry->entry_date->format('d M Y') }}.</p>
                    </div>
                </div>
            </div>
            @else
            @can('accounting settings')
            <form method="POST" action="{{ route('management.accounting.settings.opening-balances.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">As of Date</label>
                    <input type="date" name="as_of" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    @foreach(['cash' => 'Cash on Hand', 'bank' => 'Bank', 'gateway_clearing' => 'Gateway Clearing', 'accounts_receivable' => 'Accounts Receivable', 'inventory' => 'Inventory', 'fixed_assets' => 'Fixed Assets', 'accounts_payable' => 'Accounts Payable (owed)', 'tax_payable' => 'VAT Payable (owed)', 'owner_equity' => "Owner's Equity"] as $key => $label)
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">{{ $label }}</label>
                        <input type="number" step="0.01" min="0" name="{{ $key }}" placeholder="0.00" class="w-full rounded-lg border-slate-300 px-3 py-2 text-xs text-right shadow-sm">
                    </div>
                    @endforeach
                </div>
                <p class="text-xs text-slate-400">Debits (assets) and credits (payables/equity) are balanced automatically via Opening Balance Equity.</p>
                <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800" onclick="return confirm('Post opening balances? This can only be done once.')">Post Opening Balances</button>
            </form>
            @endcan
            @endif
        </div>
    </div>

    <div class="space-y-6">
        @can('accounting close')
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-800">Fiscal Years</h2>
                <p class="text-xs text-slate-400 mt-0.5">Closing a year transfers its net result to retained earnings and locks its periods.</p>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse($fiscalYears as $year)
                <div class="px-5 py-3 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-700">{{ $year->name }}</p>
                        <p class="text-xs text-slate-400">{{ $year->start_date->format('d M Y') }} – {{ $year->end_date->format('d M Y') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $year->isOpen() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $year->isOpen() ? 'Open' : 'Closed' }}</span>
                        @if($year->isOpen())
                        <form method="POST" action="{{ route('management.accounting.settings.years.close', $year->name) }}" onsubmit="return confirm('Close fiscal year {{ $year->name }}? Net income will move to retained earnings and all periods will be locked.')">
                            @csrf
                            <button class="px-2.5 py-1 text-xs font-medium text-amber-600 bg-amber-50 rounded-md hover:bg-amber-100">Close Year</button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <div class="px-5 py-6 text-center text-xs text-slate-400">No fiscal years yet.</div>
                @endforelse
            </div>
        </div>
        @endcan

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-800">Fiscal Periods</h2>
                <p class="text-xs text-slate-400 mt-0.5">Closed periods reject new entries.</p>
            </div>
        <div class="max-h-[640px] overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100 sticky top-0">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Period</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Range</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                        @can('accounting settings')<th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Action</th>@endcan
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($periods as $period)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ $period->name }}</td>
                        <td class="px-5 py-3 text-xs text-slate-500 hidden sm:table-cell">{{ $period->start_date->format('d M') }} – {{ $period->end_date->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $period->isOpen() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $period->isOpen() ? 'Open' : 'Closed' }}</span>
                        </td>
                        @can('accounting settings')
                        <td class="px-5 py-3 text-right">
                            @if($period->isOpen())
                            <form method="POST" action="{{ route('management.accounting.settings.periods.close', $period) }}" onsubmit="return confirm('Close period {{ $period->name }}? No further entries will be allowed.')">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-amber-600 bg-amber-50 rounded-md hover:bg-amber-100">Close</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('management.accounting.settings.periods.reopen', $period) }}" onsubmit="return confirm('Reopen period {{ $period->name }}?')">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Reopen</button>
                            </form>
                            @endif
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>
    </div>
</div>

@endsection
