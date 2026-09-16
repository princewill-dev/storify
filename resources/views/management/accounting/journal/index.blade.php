@extends('management.layout')
@section('subtitle', 'Journal Entries')

@section('content')

<x-management.page-header title="Journal Entries" subtitle="Every posted entry in your general ledger">
    <x-slot:actions>
        @can('accounting journal')
        <a href="{{ route('management.accounting.journal.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
            <i class="fi fi-rr-plus text-xs"></i> Manual Entry
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <form method="GET" class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Search entry, reference, memo..." class="flex-1 min-w-[180px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
            <option value="">All Statuses</option>
            @foreach(['posted', 'void', 'draft'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm w-[140px]">
        <input type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm w-[140px]">
        <button class="px-3 py-2 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800">Filter</button>
        @if(request()->hasAny(['q', 'status', 'from', 'to']))
        <a href="{{ route('management.accounting.journal.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
        @endif
    </form>

    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Entry</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Memo</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Debit</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Credit</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($entries as $entry)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3">
                    <a href="{{ route('management.accounting.journal.show', $entry) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $entry->entry_number }}</a>
                    @if($entry->reference)<div class="text-xs text-slate-400">{{ $entry->reference }}</div>@endif
                </td>
                <td class="px-5 py-3 text-xs text-slate-500">{{ $entry->entry_date->format('d M Y') }}</td>
                <td class="px-5 py-3 text-slate-600 hidden md:table-cell truncate max-w-[260px]">{{ $entry->memo ?? '—' }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format(($entry->lines->sum('debit') ?? 0) / 100, 2) }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800 hidden sm:table-cell">₦{{ number_format(($entry->lines->sum('credit') ?? 0) / 100, 2) }}</td>
                <td class="px-5 py-3 text-center">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : ($entry->status === 'void' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500') }}">{{ ucfirst($entry->status) }}</span>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-book" title="No journal entries" description="Entries are posted automatically from sales, payments, refunds, and expenses." />
            </td></tr>
            @endforelse
        </tbody>
    </table>

    @if($entries->hasPages())
    <div class="px-5 py-3 border-t border-slate-100">{{ $entries->links() }}</div>
    @endif
</div>

@endsection
