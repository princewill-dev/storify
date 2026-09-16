@extends('management.layout')
@section('subtitle', 'Bank Reconciliation')

@section('content')

<div x-data="{ importOpen: false }">
    <x-management.page-header title="Bank Reconciliation" subtitle="Import bank statements and match them to your ledger">
        <x-slot:actions>
            <button @click="importOpen = true" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
                <i class="fi fi-rr-upload text-xs"></i> Import Statement
            </button>
        </x-slot:actions>
    </x-management.page-header>

    @if($imports->isEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12">
        <x-management.empty-state icon="fi fi-rr-bank" title="No statements imported" description="Import a CSV bank statement to start reconciling. Expected columns: date, description, reference, amount." />
    </div>
    @else
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Statement</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Account</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Lines</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Unmatched</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($imports as $import)
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3">
                        <div class="font-medium text-slate-700">{{ $import->statement_date?->format('d M Y') ?? 'Statement #'.$import->id }}</div>
                        <div class="text-xs text-slate-400">Imported {{ $import->created_at->format('d M Y H:i') }}</div>
                    </td>
                    <td class="px-5 py-3 text-slate-600 hidden md:table-cell">{{ $import->ledgerAccount?->name ?? '—' }}<div class="text-xs text-slate-400">{{ $import->storeBank?->bank_name }}</div></td>
                    <td class="px-5 py-3 text-right text-slate-600">{{ $import->lines_count }}</td>
                    <td class="px-5 py-3 text-right {{ $import->unmatched_count > 0 ? 'text-amber-600 font-semibold' : 'text-emerald-600' }}">{{ $import->unmatched_count }}</td>
                    <td class="px-5 py-3 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $import->status === 'reconciled' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ ucfirst($import->status) }}</span>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('management.accounting.reconciliation.show', $import) }}" class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Open</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($imports->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">{{ $imports->links() }}</div>
        @endif
    </div>
    @endif

    @if($reconciliations->isNotEmpty())
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Completed Reconciliations</h2></div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Period</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Account</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Statement</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ledger</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Difference</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($reconciliations as $rec)
                <tr>
                    <td class="px-5 py-3 text-xs text-slate-500">{{ $rec->start_date->format('d M Y') }} – {{ $rec->end_date->format('d M Y') }}</td>
                    <td class="px-5 py-3 text-slate-600 hidden md:table-cell">{{ $rec->ledgerAccount?->name ?? '—' }}</td>
                    <td class="px-5 py-3 text-right text-slate-700">₦{{ number_format($rec->statement_closing_balance_kobo / 100, 2) }}</td>
                    <td class="px-5 py-3 text-right text-slate-700">₦{{ number_format($rec->cleared_balance_kobo / 100, 2) }}</td>
                    <td class="px-5 py-3 text-right font-semibold {{ $rec->difference_kobo === 0 ? 'text-emerald-600' : 'text-red-600' }}">₦{{ number_format($rec->difference_kobo / 100, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Import modal --}}
    <div x-show="importOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/50" @click="importOpen = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-slate-900 mb-4">Import Bank Statement</h3>
            <form method="POST" action="{{ route('management.accounting.reconciliation.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Bank Account</label>
                    <select name="ledger_account_id" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">Select account...</option>
                        @foreach($bankAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Linked Bank <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select name="store_bank_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">None</option>
                        @foreach($storeBanks as $bank)
                        <option value="{{ $bank->id }}">{{ $bank->bank_name }} — {{ $bank->getMaskedAccountNumberAttribute() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Statement Date</label>
                        <input type="date" name="statement_date" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Closing Balance (₦)</label>
                        <input type="number" step="0.01" name="closing_balance" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">CSV File</label>
                    <input type="file" name="file" accept=".csv,.txt" required class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm shadow-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-slate-100 file:text-slate-600 file:text-xs">
                    <p class="text-xs text-slate-400 mt-1">Columns: date, description, reference, amount (positive = inflow, negative = outflow).</p>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Import</button>
                    <button type="button" @click="importOpen = false" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
