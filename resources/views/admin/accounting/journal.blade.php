@extends('admin.layout')
@section('subtitle', 'Platform Journal')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Platform Journal</h2>
        <p class="text-sm text-slate-500 mt-0.5">All entries in Storify's own ledger.</p>
    </div>
    <a href="{{ route('admin.accounting.index') }}" class="px-4 py-2.5 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Back</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <form method="GET" class="px-5 py-3 border-b border-slate-100">
        <input name="q" value="{{ request('q') }}" placeholder="Search entry, reference, memo..." class="w-full max-w-sm rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
    </form>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100 bg-slate-50/50">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Entry</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Date</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Memo</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Lines</th>
                <th class="text-center py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($entries as $entry)
            <tr class="hover:bg-slate-50/50">
                <td class="py-3 px-4"><a href="{{ route('admin.accounting.journal.show', $entry) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $entry->entry_number }}</a></td>
                <td class="py-3 px-4 text-xs text-slate-500">{{ $entry->entry_date->format('d M Y') }}</td>
                <td class="py-3 px-4 text-slate-600 truncate max-w-[280px]">{{ $entry->memo ?? '—' }}</td>
                <td class="py-3 px-4 text-right text-slate-500">{{ $entry->lines_count }}</td>
                <td class="py-3 px-4 text-center">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($entry->status) }}</span>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="py-12 text-center text-sm text-slate-400">No platform journal entries yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($entries->hasPages())
    <div class="px-5 py-3 border-t border-slate-100">{{ $entries->links() }}</div>
    @endif
</div>
@endsection
