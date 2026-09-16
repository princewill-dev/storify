@extends('management.layout')
@section('subtitle', 'Reconcile Statement')

@section('content')

<div x-data="{ matchOpen: false, matchLineId: null, completeOpen: false }">
    <x-management.page-header
        title="Reconcile: {{ $import->ledgerAccount?->name }}"
        subtitle="{{ $import->statement_date ? 'Statement dated '.$import->statement_date->format('d M Y') : 'Statement #'.$import->id }}">
        <x-slot:actions>
            <form method="POST" action="{{ route('management.accounting.reconciliation.auto-match', $import) }}">
                @csrf
                <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
                    <i class="fi fi-rr-magic-wand text-xs"></i> Auto-match
                </button>
            </form>
            <button @click="completeOpen = true" class="px-4 py-2 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Complete</button>
            <a href="{{ route('management.accounting.reconciliation.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
        </x-slot:actions>
    </x-management.page-header>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Statement Closing</p>
            <p class="text-base font-bold text-slate-900 mt-1">₦{{ number_format(($import->closing_balance_kobo ?? 0) / 100, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Ledger Balance</p>
            <p class="text-base font-bold text-slate-900 mt-1">₦{{ number_format($ledgerClosing / 100, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Difference</p>
            <p class="text-base font-bold {{ ($import->closing_balance_kobo !== null && ($import->closing_balance_kobo - $ledgerClosing) === 0) ? 'text-emerald-600' : 'text-amber-600' }} mt-1">
                {{ $import->closing_balance_kobo === null ? '—' : '₦'.number_format(($import->closing_balance_kobo - $ledgerClosing) / 100, 2) }}
            </p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Unmatched</p>
            <p class="text-base font-bold {{ $unmatchedCount > 0 ? 'text-amber-600' : 'text-emerald-600' }} mt-1">{{ $unmatchedCount }} line(s)</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Reference</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($import->lines as $line)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 text-xs text-slate-500">{{ $line->transaction_date->format('d M Y') }}</td>
                    <td class="px-5 py-3 text-slate-700">{{ $line->description ?? '—' }}</td>
                    <td class="px-5 py-3 text-slate-400 text-xs hidden md:table-cell">{{ $line->reference ?? '—' }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $line->amount_kobo >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $line->amount_kobo >= 0 ? '+' : '−' }}₦{{ number_format(abs($line->amount_kobo) / 100, 2) }}
                    </td>
                    <td class="px-5 py-3 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $line->status === 'matched' ? 'bg-emerald-50 text-emerald-700' : ($line->status === 'ignored' ? 'bg-slate-100 text-slate-500' : 'bg-amber-50 text-amber-700') }}">{{ ucfirst($line->status) }}</span>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1.5">
                            @if($line->status === 'unmatched')
                            <button @click="matchLineId = {{ $line->id }}; matchOpen = true" class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Match</button>
                            <form method="POST" action="{{ route('management.accounting.reconciliation.lines.ignore', $line) }}">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-slate-500 bg-slate-100 rounded-md hover:bg-slate-200">Ignore</button>
                            </form>
                            @elseif($line->status === 'matched')
                            <form method="POST" action="{{ route('management.accounting.reconciliation.lines.unmatch', $line) }}">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium text-amber-600 bg-amber-50 rounded-md hover:bg-amber-100">Unmatch</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-5 py-12">
                    <x-management.empty-state icon="fi fi-rr-bank" title="No statement lines" description="Re-import the statement with valid rows." />
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Manual match modal --}}
    <div x-show="matchOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="matchOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4">Match to Ledger Entry</h3>
            <form method="POST" :action="'{{ url('management/accounting/reconciliation/lines') }}/' + matchLineId + '/match'" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Ledger Line</label>
                    <select name="journal_line_id" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">Select ledger entry...</option>
                        @foreach($candidates as $candidate)
                        <option value="{{ $candidate->id }}">
                            {{ \Illuminate\Support\Carbon::parse($candidate->entry_date)->format('d M Y') }} — {{ $candidate->entry_number }} —
                            {{ $candidate->debit_kobo > 0 ? 'Dr' : 'Cr' }} ₦{{ number_format(($candidate->debit_kobo > 0 ? $candidate->debit_kobo : $candidate->credit_kobo) / 100, 2) }}
                            {{ $candidate->description ? ' — '.$candidate->description : '' }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Match</button>
                    <button type="button" @click="matchOpen = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Complete modal --}}
    <div x-show="completeOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="completeOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4">Complete Reconciliation</h3>
            <form method="POST" action="{{ route('management.accounting.reconciliation.complete', $import) }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Statement Closing Balance (₦)</label>
                    <input type="number" step="0.01" name="statement_closing_balance" value="{{ $import->closing_balance_kobo !== null ? $import->closing_balance_kobo / 100 : '' }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Period Start</label>
                        <input type="date" name="start_date" value="{{ $import->lines->min('transaction_date')?->toDateString() ?? now()->startOfMonth()->toDateString() }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Period End</label>
                        <input type="date" name="end_date" value="{{ $import->lines->max('transaction_date')?->toDateString() ?? now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Reconciliation</button>
                    <button type="button" @click="completeOpen = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
