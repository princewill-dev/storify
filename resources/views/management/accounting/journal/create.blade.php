@extends('management.layout')
@section('subtitle', 'Manual Journal Entry')

@section('content')

<x-management.page-header title="Manual Journal Entry" subtitle="Post a balanced debit/credit entry" />

<div x-data="journalEntry" class="max-w-4xl">
    <form method="POST" action="{{ route('management.accounting.journal.store') }}" class="space-y-6">
        @csrf

        @if($errors->any())
        <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Date</label>
                <input type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Reference <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="text" name="reference" value="{{ old('reference') }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div class="sm:col-span-1">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Memo</label>
                <input type="text" name="memo" value="{{ old('memo') }}" placeholder="What is this entry for?" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800">Lines</h2>
                <button type="button" @click="addLine()" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200">
                    <i class="fi fi-rr-plus text-[10px]"></i> Add Line
                </button>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Account</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Description</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-36">Debit (₦)</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-36">Credit (₦)</th>
                        <th class="px-4 py-2.5 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <template x-for="(line, index) in lines" :key="line.key">
                        <tr>
                            <td class="px-4 py-2">
                                <select :name="'lines['+index+'][ledger_account_id]'" x-model="line.account_id" required class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                                    <option value="">Select account...</option>
                                    @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-2 hidden md:table-cell">
                                <input type="text" :name="'lines['+index+'][description]'" x-model="line.description" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" step="0.01" min="0" :name="'lines['+index+'][debit]'" x-model.number="line.debit" @input="line.credit = ''" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs text-right shadow-sm">
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" step="0.01" min="0" :name="'lines['+index+'][credit]'" x-model.number="line.credit" @input="line.debit = ''" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs text-right shadow-sm">
                            </td>
                            <td class="px-4 py-2 text-center">
                                <button type="button" @click="removeLine(index)" class="text-red-500 hover:text-red-700"><i class="fi fi-rr-cross text-xs"></i></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr>
                        <td colspan="2" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Totals</td>
                        <td class="px-4 py-3 text-right font-bold" :class="balanced ? 'text-emerald-600' : 'text-slate-900'" x-text="formatMoney(totalDebit)"></td>
                        <td class="px-4 py-3 text-right font-bold" :class="balanced ? 'text-emerald-600' : 'text-slate-900'" x-text="formatMoney(totalCredit)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <div class="px-5 py-3 border-t border-slate-100">
                <p class="text-xs" :class="balanced && totalDebit > 0 ? 'text-emerald-600' : 'text-amber-600'" x-text="statusText"></p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" :disabled="!balanced || totalDebit <= 0" :class="(!balanced || totalDebit <= 0) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-800'" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg">Post Entry</button>
            <button type="submit" name="save_as_draft" value="1" :disabled="totalDebit <= 0 && totalCredit <= 0" :class="(totalDebit <= 0 && totalCredit <= 0) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-50'" class="px-4 py-2.5 border border-slate-200 text-sm font-semibold text-slate-600 rounded-lg">Save as Draft</button>
            <a href="{{ route('management.accounting.journal.index') }}" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
        </div>
    </form>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('journalEntry', () => ({
        lines: [],
        keyCounter: 0,
        init() {
            this.addLine();
            this.addLine();
        },
        addLine() {
            this.lines.push({ key: this.keyCounter++, account_id: '', description: '', debit: '', credit: '' });
        },
        removeLine(index) {
            if (this.lines.length > 2) {
                this.lines.splice(index, 1);
            }
        },
        get totalDebit() {
            return this.lines.reduce((sum, line) => sum + (parseFloat(line.debit) || 0), 0);
        },
        get totalCredit() {
            return this.lines.reduce((sum, line) => sum + (parseFloat(line.credit) || 0), 0);
        },
        get balanced() {
            return Math.abs(this.totalDebit - this.totalCredit) < 0.005 && this.totalDebit > 0;
        },
        get statusText() {
            if (this.totalDebit <= 0 && this.totalCredit <= 0) return 'Add at least two lines with amounts.';
            if (this.balanced) return 'Balanced — ready to post.';
            return 'Debits and credits must match. Difference: ' + this.formatMoney(Math.abs(this.totalDebit - this.totalCredit));
        },
        formatMoney(value) {
            return '₦' + (value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    }));
});
</script>
@endpush
