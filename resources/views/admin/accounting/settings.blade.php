@extends('admin.layout')
@section('subtitle', 'Platform Accounting Settings')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Platform Accounting Settings</h2>
        <p class="text-sm text-slate-500 mt-0.5">Auto-posting mappings and fiscal periods for Storify's books.</p>
    </div>
    <a href="{{ route('admin.accounting.index') }}" class="px-4 py-2.5 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Back</a>
</div>

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
        'sales_income' => 'Subscription Revenue',
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
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Auto-Posting Accounts</h3>
            <p class="text-xs text-slate-400 mt-0.5">Which account each platform money event posts to.</p>
        </div>
        <form method="POST" action="{{ route('admin.accounting.settings.mappings.update') }}">
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

    <div class="space-y-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Fiscal Years</h3>
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
                    <form method="POST" action="{{ route('admin.accounting.settings.years.close', $year->name) }}" onsubmit="return confirm('Close fiscal year {{ $year->name }}? Net income will move to retained earnings and all periods will be locked.')">
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

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Fiscal Periods</h3>
            <p class="text-xs text-slate-400 mt-0.5">Closed periods reject new entries.</p>
        </div>
        <div class="max-h-[640px] overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 bg-slate-50/50 sticky top-0">
                    <tr>
                        <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Period</th>
                        <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Range</th>
                        <th class="text-center py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Status</th>
                        <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($periods as $period)
                    <tr class="hover:bg-slate-50/50">
                        <td class="py-3 px-4 font-medium text-slate-700">{{ $period->name }}</td>
                        <td class="py-3 px-4 text-xs text-slate-500">{{ $period->start_date->format('d M') }} – {{ $period->end_date->format('d M Y') }}</td>
                        <td class="py-3 px-4 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $period->isOpen() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $period->isOpen() ? 'Open' : 'Closed' }}</span>
                        </td>
                        <td class="py-3 px-4 text-right">
                            @if($period->isOpen())
                            <form method="POST" action="{{ route('admin.accounting.settings.periods.close', $period) }}" onsubmit="return confirm('Close period {{ $period->name }}?')">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-amber-600 bg-amber-50 rounded-md hover:bg-amber-100">Close</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.accounting.settings.periods.reopen', $period) }}" onsubmit="return confirm('Reopen period {{ $period->name }}?')">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Reopen</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    </div>
</div>
@endsection
