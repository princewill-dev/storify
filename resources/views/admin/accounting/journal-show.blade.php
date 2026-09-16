@extends('admin.layout')
@section('subtitle', $entry->entry_number)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">{{ $entry->entry_number }}</h2>
        <p class="text-sm text-slate-500 mt-0.5">{{ $entry->memo ?? 'Platform journal entry' }}</p>
    </div>
    <a href="{{ route('admin.accounting.journal') }}" class="px-4 py-2.5 border border-slate-200 text-sm font-medium text-slate-600 rounded-lg hover:bg-slate-50">Back</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Lines</h3></div>
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 bg-slate-50/50">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Account</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Description</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Debit</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600 text-xs uppercase tracking-wider">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($entry->lines as $line)
                <tr>
                    <td class="py-3 px-4"><span class="font-mono text-xs text-slate-400">{{ $line->account?->code }}</span> <span class="text-slate-800 ml-1">{{ $line->account?->name }}</span></td>
                    <td class="py-3 px-4 text-slate-500">{{ $line->description ?? '—' }}</td>
                    <td class="py-3 px-4 text-right font-medium text-slate-700">{{ $line->debit_kobo > 0 ? '₦'.number_format($line->debit_kobo / 100, 2) : '—' }}</td>
                    <td class="py-3 px-4 text-right font-medium text-slate-700">{{ $line->credit_kobo > 0 ? '₦'.number_format($line->credit_kobo / 100, 2) : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-slate-100 bg-slate-50/50">
                <tr>
                    <td colspan="2" class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                    <td class="py-3 px-4 text-right font-bold text-slate-900">₦{{ number_format($entry->totalDebits() / 100, 2) }}</td>
                    <td class="py-3 px-4 text-right font-bold text-slate-900">₦{{ number_format($entry->totalCredits() / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Details</h3>
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd class="font-medium text-slate-800">{{ $entry->entry_date->format('d M Y') }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($entry->status) }}</span></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Reference</dt><dd class="font-medium text-slate-800">{{ $entry->reference ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Period</dt><dd class="font-medium text-slate-800">{{ $entry->fiscalPeriod?->name ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Posted by</dt><dd class="font-medium text-slate-800">{{ $entry->postedBy?->name ?? 'System' }}</dd></div>
        </dl>
    </div>
</div>
@endsection
