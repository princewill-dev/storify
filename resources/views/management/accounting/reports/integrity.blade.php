@extends('management.layout')
@section('subtitle', 'Ledger Integrity')

@section('content')

<x-management.page-header title="Ledger Integrity" subtitle="Checks that your ledger matches operational data">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export CSV</a>
        <a href="{{ route('management.accounting.reports.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Trial Balance</p>
        <p class="text-base font-bold {{ $report['trial_balanced'] ? 'text-emerald-600' : 'text-red-600' }} mt-1">
            {{ $report['trial_balanced'] ? '✓ Balanced' : '✗ Out of balance' }}
        </p>
        <p class="text-xs text-slate-400 mt-1">Dr ₦{{ number_format($report['trial_debit'] / 100, 2) }} · Cr ₦{{ number_format($report['trial_credit'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Unposted Payments</p>
        <p class="text-base font-bold {{ $report['unposted_transactions'] > 0 ? 'text-amber-600' : 'text-emerald-600' }} mt-1">{{ $report['unposted_transactions'] }}</p>
        <p class="text-xs text-slate-400 mt-1">Run <code class="px-1 bg-slate-100 rounded">php artisan ledger:reconcile --post</code> to backfill.</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Accounts Receivable</p>
        <p class="text-base font-bold {{ $report['ar_difference'] === 0 ? 'text-emerald-600' : 'text-amber-600' }} mt-1">
            {{ $report['ar_difference'] === 0 ? '✓ Matched' : '₦'.number_format(abs($report['ar_difference']) / 100, 2).' gap' }}
        </p>
        <p class="text-xs text-slate-400 mt-1">Ledger ₦{{ number_format($report['ledger_ar'] / 100, 2) }} · Documents ₦{{ number_format($report['documents_ar'] / 100, 2) }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold text-slate-800">Store Wallet vs Ledger Cash</h2>
        <p class="text-xs text-slate-400 mt-0.5">The POS wallet should track the ledger's cash, bank, and gateway clearing accounts per store.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Wallet</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ledger Cash</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Difference</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($report['wallet_rows'] as $row)
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3 text-slate-700">{{ $row['store']->name }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($row['wallet'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($row['ledger'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-semibold {{ $row['difference'] === 0 ? 'text-emerald-600' : 'text-amber-600' }}">₦{{ number_format($row['difference'] / 100, 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-shield-check" title="No stores to compare" description="Create a store to see wallet vs ledger checks." />
            </td></tr>
            @endforelse
        </tbody>
        @if(count($report['wallet_rows']) > 0)
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr>
                <td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['wallet_total'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['ledger_cash_total'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-bold {{ ($report['wallet_total'] - $report['ledger_cash_total']) === 0 ? 'text-emerald-600' : 'text-amber-600' }}">₦{{ number_format(($report['wallet_total'] - $report['ledger_cash_total']) / 100, 2) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

<div class="mt-6 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
    <p class="font-semibold mb-1">Why might these differ?</p>
    <ul class="list-disc list-inside space-y-0.5 text-amber-700 text-xs">
        <li>Historical payments recorded before the ledger went live (use opening balances or <code class="px-1 bg-amber-100 rounded">ledger:reconcile --post</code>).</li>
        <li>Store wallet credits that happened before balance audit columns existed.</li>
        <li>Manual journal entries that don't touch store-specific lines.</li>
    </ul>
</div>

@endsection
