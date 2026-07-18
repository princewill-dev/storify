@extends('admin.layout')
@section('subtitle', 'Create Bank Account')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.bank-accounts.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">← Back to Bank Accounts</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
        <h2 class="text-lg font-bold text-slate-900">Add New Bank Account</h2>
    </div>
    <div class="p-6">
        <form method="POST" action="{{ route('admin.bank-accounts.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="bank_name" class="block text-sm font-medium text-slate-700 mb-1">Bank Name <span class="text-red-500">*</span></label>
                    <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('bank_name') border-red-500 @enderror" id="bank_name" name="bank_name" value="{{ old('bank_name') }}" required>
                    @error('bank_name')
                        <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="account_number" class="block text-sm font-medium text-slate-700 mb-1">Account Number <span class="text-red-500">*</span></label>
                    <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('account_number') border-red-500 @enderror" id="account_number" name="account_number" value="{{ old('account_number') }}" required>
                    @error('account_number')
                        <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="account_name" class="block text-sm font-medium text-slate-700 mb-1">Account Name</label>
                    <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('account_name') border-red-500 @enderror" id="account_name" name="account_name" value="{{ old('account_name') }}">
                    <p class="text-xs text-slate-400 mt-1">e.g., Zimoziswift Limited</p>
                    @error('account_name')
                        <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="sort_order" class="block text-sm font-medium text-slate-700 mb-1">Sort Order</label>
                        <input type="number" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('sort_order') border-red-500 @enderror" id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}">
                        <p class="text-xs text-slate-400 mt-1">Lower numbers appear first</p>
                        @error('sort_order')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="is_active" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                        <select class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('is_active') border-red-500 @enderror" id="is_active" name="is_active">
                            <option value="1" {{ old('is_active', 1) == 1 ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ old('is_active') == 0 ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('is_active')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label for="logo" class="block text-sm font-medium text-slate-700 mb-1">Bank Logo</label>
                    <input type="file" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 @error('logo') border-red-500 @enderror" id="logo" name="logo" accept="image/*">
                    <p class="text-xs text-slate-400 mt-1">Upload bank logo (JPEG, PNG, GIF - Max 2MB)</p>
                    @error('logo')
                        <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                <a href="{{ route('admin.bank-accounts.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create Bank Account</button>
            </div>
        </form>
    </div>
</div>
@endsection
