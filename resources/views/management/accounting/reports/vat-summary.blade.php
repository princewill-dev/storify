@extends('management.layout')
@section('subtitle', 'VAT Summary')

@section('content')

<x-management.page-header title="VAT Summary" subtitle="{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}">
    <x-slot:actions>
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
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Output VAT (Sales)</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($report['output_tax'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Input VAT (Purchases)</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($report['input_tax'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Net VAT Payable</p>
        <p class="text-xl font-bold {{ $report['net_payable'] >= 0 ? 'text-amber-600' : 'text-emerald-600' }} mt-1">₦{{ number_format($report['net_payable'] / 100, 2) }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-2xl">
    <table class="w-full text-sm">
        <tbody class="divide-y divide-slate-50">
            <tr><td class="px-5 py-3 text-slate-600">VAT collected on sales</td><td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($report['output_tax'] / 100, 2) }}</td></tr>
            <tr><td class="px-5 py-3 text-slate-600">Less: VAT paid on purchases & expenses</td><td class="px-5 py-3 text-right font-semibold text-slate-800">(₦{{ number_format($report['input_tax'] / 100, 2) }})</td></tr>
        </tbody>
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Net VAT {{ $report['net_payable'] >= 0 ? 'Payable' : 'Refundable' }}</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format(abs($report['net_payable']) / 100, 2) }}</td></tr>
        </tfoot>
    </table>
</div>

@endsection
