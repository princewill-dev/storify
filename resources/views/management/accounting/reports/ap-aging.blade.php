@extends('management.layout')
@section('subtitle', 'AP Aging')

@section('content')

<x-management.page-header title="Accounts Payable Aging" subtitle="Outstanding supplier bills as of {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export CSV</a>
        <a href="{{ route('management.accounting.reports.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
    <label class="text-sm text-slate-500">As of</label>
    <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <button class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Run Report</button>
</form>

<div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
    @foreach($report['buckets'] as $bucket)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">{{ $bucket['label'] }}</p>
        <p class="text-base font-bold text-slate-900 mt-1">₦{{ number_format($bucket['total'] / 100, 2) }}</p>
    </div>
    @endforeach
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Bill</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Supplier</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Due</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Paid</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Outstanding</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Age</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse(collect($report['buckets'])->flatMap(fn ($b) => $b['rows']) as $row)
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3 font-medium text-slate-700">{{ $row['reference'] }}</td>
                <td class="px-5 py-3 text-slate-600">{{ $row['contact'] }}</td>
                <td class="px-5 py-3 text-xs text-slate-500 hidden md:table-cell">{{ $row['due_date'] ? \Illuminate\Support\Carbon::parse($row['due_date'])->format('d M Y') : '—' }}</td>
                <td class="px-5 py-3 text-right text-slate-600">₦{{ number_format($row['total'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right text-slate-500 hidden sm:table-cell">₦{{ number_format($row['paid'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-semibold text-amber-600">₦{{ number_format($row['outstanding'] / 100, 2) }}</td>
                <td class="px-5 py-3 text-center text-xs {{ $row['days_past_due'] > 30 ? 'text-red-600 font-medium' : 'text-slate-500' }}">{{ $row['days_past_due'] }}d</td>
            </tr>
            @empty
            <tr><td colspan="7" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-time-forward" title="Nothing outstanding" description="All supplier bills are settled." />
            </td></tr>
            @endforelse
        </tbody>
        @if($report['grand_total'] > 0)
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr>
                <td colspan="5" class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Outstanding</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['grand_total'] / 100, 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

@endsection
