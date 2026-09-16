@extends('management.layout')
@section('subtitle', 'Trial Balance')

@section('content')

<x-management.page-header title="Trial Balance" subtitle="{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export PDF</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export CSV</a>
        <a href="{{ route('management.accounting.reports.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
    <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <button class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Run Report</button>
</form>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Account</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Debit</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Credit</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($report['lines'] as $line)
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3 font-mono text-xs text-slate-400">{{ $line['account']->code }}</td>
                <td class="px-5 py-3 text-slate-700">{{ $line['account']->name }}</td>
                <td class="px-5 py-3 text-right {{ $line['debit'] > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' }}">{{ $line['debit'] > 0 ? '₦'.number_format($line['debit'] / 100, 2) : '—' }}</td>
                <td class="px-5 py-3 text-right {{ $line['credit'] > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' }}">{{ $line['credit'] > 0 ? '₦'.number_format($line['credit'] / 100, 2) : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-list" title="No posted entries in this period" description="Post sales, payments, or expenses to populate the trial balance." />
            </td></tr>
            @endforelse
        </tbody>
        @if(count($report['lines']) > 0)
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr>
                <td colspan="2" class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['total_debit'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['total_credit'] / 100, 2) }}</td>
            </tr>
            <tr>
                <td colspan="4" class="px-5 pb-3 text-right text-xs {{ $report['total_debit'] === $report['total_credit'] ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $report['total_debit'] === $report['total_credit'] ? '✓ Balanced' : '✗ Out of balance' }}
                </td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

@endsection
