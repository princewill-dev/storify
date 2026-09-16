@extends('management.layout')
@section('subtitle', $entry->entry_number)

@section('content')

<x-management.page-header :title="$entry->entry_number" :subtitle="$entry->memo ?? 'Journal entry'">
    <x-slot:actions>
        @if($entry->status === 'draft')
        @can('accounting journal')
        <form method="POST" action="{{ route('management.accounting.journal.post', $entry) }}">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-500">
                <i class="fi fi-rr-check text-xs"></i> Post Entry
            </button>
        </form>
        <form method="POST" action="{{ route('management.accounting.journal.destroy', $entry) }}" onsubmit="return confirm('Delete this draft?')">
            @csrf
            @method('DELETE')
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-lg hover:bg-red-100">
                <i class="fi fi-rr-trash text-xs"></i> Delete Draft
            </button>
        </form>
        @endcan
        @endif
        @if($entry->status === 'posted')
        @can('accounting journal')
        <form method="POST" action="{{ route('management.accounting.journal.reverse', $entry) }}" onsubmit="return confirm('Post a reversing entry for {{ $entry->entry_number }}?')">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-lg hover:bg-red-100">
                <i class="fi fi-rr-rotate-left text-xs"></i> Reverse Entry
            </button>
        </form>
        @endcan
        @endif
        <a href="{{ route('management.accounting.journal.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Lines</h2></div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Account</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Description</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Debit</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Credit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($entry->lines as $line)
                    <tr>
                        <td class="px-5 py-3">
                            <span class="font-mono text-xs text-slate-400">{{ $line->account?->code }}</span>
                            <span class="font-medium text-slate-800 ml-2">{{ $line->account?->name }}</span>
                        </td>
                        <td class="px-5 py-3 text-slate-500 hidden md:table-cell">{{ $line->description ?? '—' }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $line->debit_kobo > 0 ? 'text-slate-800' : 'text-slate-300' }}">{{ $line->debit_kobo > 0 ? '₦'.number_format($line->debit_kobo / 100, 2) : '—' }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $line->credit_kobo > 0 ? 'text-slate-800' : 'text-slate-300' }}">{{ $line->credit_kobo > 0 ? '₦'.number_format($line->credit_kobo / 100, 2) : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr>
                        <td colspan="2" class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                        <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($entry->totalDebits() / 100, 2) }}</td>
                        <td class="px-5 py-3 text-right font-bold text-slate-900">₦{{ number_format($entry->totalCredits() / 100, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if($entry->journal_entry_id ?? false)
        @endif
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Details</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd class="font-medium text-slate-800">{{ $entry->entry_date->format('d M Y') }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($entry->status) }}</span>
                </dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Reference</dt><dd class="font-medium text-slate-800">{{ $entry->reference ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Period</dt><dd class="font-medium text-slate-800">{{ $entry->fiscalPeriod?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Posted by</dt><dd class="font-medium text-slate-800">{{ $entry->postedBy?->name ?? 'System' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Posted at</dt><dd class="font-medium text-slate-800">{{ $entry->posted_at?->format('d M Y H:i') ?? '—' }}</dd></div>
            </dl>
        </div>

        @if($entry->reversalOf)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            Reversal of <a href="{{ route('management.accounting.journal.show', $entry->reversalOf) }}" class="font-medium underline">{{ $entry->reversalOf->entry_number }}</a>
        </div>
        @endif
    </div>
</div>

@endsection
