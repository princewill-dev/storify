@extends('management.layout')
@section('subtitle', 'Expense')

@section('content')

<x-management.page-header :title="$expense->description ?: 'Expense #'.$expense->id" :subtitle="'₦'.number_format($expense->total_kobo / 100, 2).' on '.$expense->expense_date->format('d M Y')">
    <x-slot:actions>
        @if($expense->status !== 'void')
        @can('accounting expenses')
        <form method="POST" action="{{ route('management.accounting.expenses.void', $expense) }}" onsubmit="return confirm('Void this expense? A reversing entry will be posted.')">
            @csrf
            <button class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-50 text-red-600 text-sm font-medium rounded-lg hover:bg-red-100">
                <i class="fi fi-rr-cross-circle text-xs"></i> Void
            </button>
        </form>
        @endcan
        @endif
        <a href="{{ route('management.accounting.expenses.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Expense Details</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div><dt class="text-slate-500 text-xs">Date</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $expense->expense_date->format('d M Y') }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Status</dt><dd class="mt-0.5">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $expense->status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600' }}">{{ ucfirst($expense->status) }}</span>
                </dd></div>
                <div><dt class="text-slate-500 text-xs">Category</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $expense->category?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Account</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $expense->ledgerAccount?->code }} — {{ $expense->ledgerAccount?->name }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Supplier</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $expense->supplier?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Reference</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $expense->reference ?? '—' }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Amount</dt><dd class="font-medium text-slate-800 mt-0.5">₦{{ number_format($expense->amount_kobo / 100, 2) }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Input VAT</dt><dd class="font-medium text-slate-800 mt-0.5">₦{{ number_format($expense->tax_kobo / 100, 2) }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Total</dt><dd class="font-bold text-slate-900 mt-0.5">₦{{ number_format($expense->total_kobo / 100, 2) }}</dd></div>
                <div><dt class="text-slate-500 text-xs">Paid via</dt><dd class="font-medium text-slate-800 mt-0.5">{{ ucfirst(str_replace('_', ' ', $expense->payment_method ?? '—')) }}</dd></div>
            </dl>
            @if($expense->description)
            <div class="mt-4 pt-4 border-t border-slate-100">
                <dt class="text-slate-500 text-xs mb-1">Description</dt>
                <p class="text-sm text-slate-700">{{ $expense->description }}</p>
            </div>
            @endif
        </div>

        @if($expense->journalEntry)
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800">Journal Entry</h2>
                <a href="{{ route('management.accounting.journal.show', $expense->journalEntry) }}" class="text-xs text-blue-600 hover:text-blue-700">{{ $expense->journalEntry->entry_number }}</a>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @foreach($expense->journalEntry->lines as $line)
                    <tr>
                        <td class="px-5 py-2.5"><span class="font-mono text-xs text-slate-400">{{ $line->account?->code }}</span> <span class="text-slate-700 ml-1">{{ $line->account?->name }}</span></td>
                        <td class="px-5 py-2.5 text-right font-medium text-slate-700">{{ $line->debit_kobo > 0 ? 'Dr ₦'.number_format($line->debit_kobo / 100, 2) : '' }}</td>
                        <td class="px-5 py-2.5 text-right font-medium text-slate-700">{{ $line->credit_kobo > 0 ? 'Cr ₦'.number_format($line->credit_kobo / 100, 2) : '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="space-y-6">
        @if($expense->receipt_path)
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Receipt</h2>
            @if(str_ends_with($expense->receipt_path, '.pdf'))
            <a href="{{ asset('storage/'.$expense->receipt_path) }}" target="_blank" class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700"><i class="fi fi-rr-document"></i> View PDF</a>
            @else
            <a href="{{ asset('storage/'.$expense->receipt_path) }}" target="_blank">
                <img src="{{ asset('storage/'.$expense->receipt_path) }}" alt="Receipt" class="rounded-lg border border-slate-200 max-h-64 object-contain">
            </a>
            @endif
        </div>
        @endif
    </div>
</div>

@endsection
