@extends('management.layout')
@section('subtitle', 'New Bill')

@section('content')

<x-management.page-header title="New Supplier Bill" subtitle="Record what you owe — posted to Accounts Payable" />

<div x-data="billForm" class="max-w-4xl">
    <form method="POST" action="{{ route('management.accounting.bills.store') }}" class="space-y-6">
        @csrf

        @if($errors->any())
        <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Supplier</label>
                <select name="supplier_id" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">Select supplier...</option>
                    @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Bill Number <span class="text-slate-400 font-normal">(auto)</span></label>
                <input type="text" name="bill_number" value="{{ old('bill_number') }}" placeholder="BILL-..." class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Issue Date</label>
                <input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Due Date <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="date" name="due_date" value="{{ old('due_date') }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
            <div class="sm:col-span-3">
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Notes <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="text" name="notes" value="{{ old('notes') }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800">Line Items</h2>
                <button type="button" @click="addItem()" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200">
                    <i class="fi fi-rr-plus text-[10px]"></i> Add Item
                </button>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Account</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-24">Qty</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-32">Unit Cost (₦)</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-28">Amount</th>
                        <th class="px-4 py-2.5 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <template x-for="(item, index) in items" :key="item.key">
                        <tr>
                            <td class="px-4 py-2">
                                <input type="text" :name="'items['+index+'][description]'" x-model="item.description" required placeholder="Item or service" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                            </td>
                            <td class="px-4 py-2 hidden lg:table-cell">
                                <select :name="'items['+index+'][expense_account_id]'" x-model="item.expense_account_id" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
                                    <option value="">Default expense</option>
                                    @foreach($expenseAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" step="0.001" min="0.001" :name="'items['+index+'][quantity]'" x-model.number="item.quantity" required class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs text-right shadow-sm">
                            </td>
                            <td class="px-4 py-2">
                                <input type="number" step="0.01" min="0" :name="'items['+index+'][unit_cost]'" x-model.number="item.unit_cost" required class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs text-right shadow-sm">
                            </td>
                            <td class="px-4 py-2 text-right font-medium text-slate-700" x-text="formatMoney(itemTotal(item))"></td>
                            <td class="px-4 py-2 text-center">
                                <button type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-700"><i class="fi fi-rr-cross text-xs"></i></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="bg-slate-50/50 border-t border-slate-100">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Subtotal</td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-800" x-text="formatMoney(subtotal)"></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Input VAT (₦)</td>
                        <td class="px-4 py-3">
                            <input type="number" step="0.01" min="0" name="tax" x-model.number="tax" class="w-full rounded-lg border-slate-300 px-2.5 py-2 text-xs text-right shadow-sm">
                        </td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</td>
                        <td class="px-4 py-3 text-right font-bold text-slate-900" x-text="formatMoney(total)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Bill</button>
            <a href="{{ route('management.accounting.bills.index') }}" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
        </div>
    </form>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('billForm', () => ({
        items: [],
        tax: 0,
        keyCounter: 0,
        init() {
            this.addItem();
        },
        addItem() {
            this.items.push({ key: this.keyCounter++, description: '', quantity: 1, unit_cost: '', expense_account_id: '' });
        },
        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
            }
        },
        itemTotal(item) {
            return (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_cost) || 0);
        },
        get subtotal() {
            return this.items.reduce((sum, item) => sum + this.itemTotal(item), 0);
        },
        get total() {
            return this.subtotal + (parseFloat(this.tax) || 0);
        },
        formatMoney(value) {
            return '₦' + (value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    }));
});
</script>
@endpush
