@extends('management.layout')
@section('subtitle', 'Expense Summary')

@section('content')

<x-management.page-header title="Expense Summary" subtitle="{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}">
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

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Expenses</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($report['grand_total'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Records</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ $report['count'] }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden max-w-3xl">
    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Category</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Count</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Share</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($report['categories'] as $name => $data)
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3 text-slate-700">{{ $name }}</td>
                <td class="px-5 py-3 text-right text-slate-500">{{ $data['count'] }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($data['total'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right text-slate-500">{{ $report['grand_total'] > 0 ? number_format($data['total'] / $report['grand_total'] * 100, 1) : '0.0' }}%</td>
            </tr>
            @empty
            <tr><td colspan="4" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-receipt" title="No expenses in this period" description="Record expenses to see them summarised here." />
            </td></tr>
            @endforelse
        </tbody>
        @if($report['grand_total'] > 0)
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr>
                <td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-700">{{ $report['count'] }}</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['grand_total'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right text-slate-500">100%</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

@endsection
