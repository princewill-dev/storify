@extends('management.layout')
@section('subtitle', 'Record Expense')

@section('content')

<x-management.page-header title="Record Expense" subtitle="Spending is posted to your ledger immediately" />

<div class="max-w-3xl">
    <form method="POST" action="{{ route('management.accounting.expenses.store') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        @if($errors->any())
        <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Date</label>
                    <input type="date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Category</label>
                    <select name="expense_category_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">No category</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('expense_category_id') == $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Supplier <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select name="supplier_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">None</option>
                        @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Expense Account</label>
                <select name="ledger_account_id" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">Select account...</option>
                    @foreach($expenseAccounts as $account)
                    <option value="{{ $account->id }}" @selected(old('ledger_account_id') == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">Choose the account this spend should hit (e.g. Rent, Utilities).</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount (₦)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Input VAT (₦) <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="number" step="0.01" min="0" name="tax" value="{{ old('tax') }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid Via</label>
                    <select name="payment_method" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid From Account <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select name="payment_account_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">Auto (based on method)</option>
                        @foreach($paymentAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('payment_account_id') == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Reference <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="text" name="reference" value="{{ old('reference') }}" placeholder="Receipt no., invoice no..." class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Receipt <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-lg border border-slate-300 px-3.5 py-2 text-sm shadow-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-slate-100 file:text-slate-600 file:text-xs">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                <textarea name="description" rows="2" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">{{ old('description') }}</textarea>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Record Expense</button>
            <a href="{{ route('management.accounting.expenses.index') }}" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
        </div>
    </form>
</div>

@endsection
