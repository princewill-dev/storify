@extends('management.layout')
@section('subtitle', 'General Ledger')

@section('content')

<x-management.page-header title="General Ledger" subtitle="{{ $report ? $report['account']->code.' — '.$report['account']->name : 'Select an account' }}">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Export CSV</a>
        <a href="{{ route('management.accounting.reports.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
    <select name="account" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm min-w-[240px]">
        @foreach($accounts as $account)
        <option value="{{ $account->id }}" @selected($accountId == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
        @endforeach
    </select>
    <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm">
    <button class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Run Report</button>
</form>

@if($report)
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Entry</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Memo</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Debit</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Credit</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            <tr class="bg-slate-50/30">
                <td colspan="5" class="px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider">Opening Balance</td>
                <td class="px-5 py-2.5 text-right font-semibold text-slate-700">₦{{ number_format($report['opening'] / 100, 2) }}</td>
            </tr>
            @forelse($report['rows'] as $row)
            <tr class="hover:bg-slate-50">
                <td class="px-5 py-3 text-xs text-slate-500">{{ $row['date'] instanceof \DateTimeInterface ? $row['date']->format('d M Y') : \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                <td class="px-5 py-3 text-slate-700">{{ $row['entry_number'] }}</td>
                <td class="px-5 py-3 text-slate-500 hidden md:table-cell truncate max-w-[260px]">{{ $row['memo'] ?? '—' }}</td>
                <td class="px-5 py-3 text-right {{ $row['debit'] > 0 ? 'font-medium text-slate-800' : 'text-slate-300' }}">{{ $row['debit'] > 0 ? '₦'.number_format($row['debit'] / 100, 2) : '—' }}</td>
                <td class="px-5 py-3 text-right {{ $row['credit'] > 0 ? 'font-medium text-slate-800' : 'text-slate-300' }}">{{ $row['credit'] > 0 ? '₦'.number_format($row['credit'] / 100, 2) : '—' }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($row['balance'] / 100, 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-xs text-slate-400">No postings in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot class="bg-slate-50/50 border-t border-slate-200">
            <tr>
                <td colspan="5" class="px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">Closing Balance</td>
                <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($report['closing'] / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endif

@endsection
