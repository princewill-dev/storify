@extends('management.layout')
@section('subtitle', 'Profit & Loss')

@section('content')

<x-management.page-header title="Profit & Loss" subtitle="{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}">
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

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Income</p>
        <p class="text-xl font-bold text-emerald-600 mt-1">₦{{ number_format($report['total_income'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Expenses</p>
        <p class="text-xl font-bold text-red-600 mt-1">₦{{ number_format($report['total_expenses'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Net Profit</p>
        <p class="text-xl font-bold {{ $report['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }} mt-1">₦{{ number_format($report['net_profit'] / 100, 2) }}</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Income</h2></div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-50">
                @forelse($report['income'] as $line)
                <tr>
                    <td class="px-5 py-3"><span class="font-mono text-xs text-slate-400">{{ $line['account']->code }}</span> <span class="text-slate-700 ml-1">{{ $line['account']->name }}</span></td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td>
                </tr>
                @empty
                <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No income in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-slate-50/50 border-t border-slate-100">
                <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Income</td><td class="px-5 py-3 text-right font-bold text-emerald-600">₦{{ number_format($report['total_income'] / 100, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Expenses</h2></div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-50">
                @forelse($report['expenses'] as $line)
                <tr>
                    <td class="px-5 py-3"><span class="font-mono text-xs text-slate-400">{{ $line['account']->code }}</span> <span class="text-slate-700 ml-1">{{ $line['account']->name }}</span></td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td>
                </tr>
                @empty
                <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No expenses in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-slate-50/50 border-t border-slate-100">
                <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Expenses</td><td class="px-5 py-3 text-right font-bold text-red-600">₦{{ number_format($report['total_expenses'] / 100, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>

@endsection
