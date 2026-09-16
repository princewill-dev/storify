@extends('management.layout')
@section('subtitle', 'Balance Sheet')

@section('content')

<x-management.page-header title="Balance Sheet" subtitle="As of {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export PDF</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export CSV</a>
        <a href="{{ route('management.accounting.reports.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
    <label class="text-sm text-slate-500">As of</label>
    <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <button class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Run Report</button>
</form>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Assets</h2></div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @forelse($report['assets'] as $line)
                    <tr>
                        <td class="px-5 py-3"><span class="font-mono text-xs text-slate-400">{{ $line['account']->code }}</span> <span class="text-slate-700 ml-1">{{ $line['account']->name }}</span></td>
                        <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No asset balances.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Assets</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['total_assets'] / 100, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Liabilities</h2></div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @forelse($report['liabilities'] as $line)
                    <tr>
                        <td class="px-5 py-3"><span class="font-mono text-xs text-slate-400">{{ $line['account']->code }}</span> <span class="text-slate-700 ml-1">{{ $line['account']->name }}</span></td>
                        <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-6 text-center text-xs text-slate-400">No liabilities.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Liabilities</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['total_liabilities'] / 100, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Equity</h2></div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @foreach($report['equity'] as $line)
                    <tr>
                        <td class="px-5 py-3"><span class="font-mono text-xs text-slate-400">{{ $line['account']->code }}</span> <span class="text-slate-700 ml-1">{{ $line['account']->name }}</span></td>
                        <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($line['amount'] / 100, 2) }}</td>
                    </tr>
                    @endforeach
                    <tr>
                        <td class="px-5 py-3 text-slate-600">Current Period Earnings</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $report['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">₦{{ number_format($report['net_profit'] / 100, 2) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr><td class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Equity</td><td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['total_equity'] / 100, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <div class="flex items-center justify-between text-sm">
                <span class="text-slate-500">Liabilities + Equity</span>
                <span class="font-bold text-slate-900">₦{{ number_format(($report['total_liabilities'] + $report['total_equity']) / 100, 2) }}</span>
            </div>
            <div class="flex items-center justify-between text-sm mt-2">
                <span class="text-slate-500">Total Assets</span>
                <span class="font-bold text-slate-900">₦{{ number_format($report['total_assets'] / 100, 2) }}</span>
            </div>
            <p class="text-xs mt-3 {{ abs($report['total_assets'] - ($report['total_liabilities'] + $report['total_equity'])) < 100 ? 'text-emerald-600' : 'text-red-600' }}">
                {{ abs($report['total_assets'] - ($report['total_liabilities'] + $report['total_equity'])) < 100 ? '✓ Balanced' : '✗ Out of balance' }}
            </p>
        </div>
    </div>
</div>

@endsection
