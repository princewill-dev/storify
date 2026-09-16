@extends('management.layout')
@section('subtitle', $account->exists ? 'Edit Account' : 'New Account')

@section('content')

<x-management.page-header :title="$account->exists ? 'Edit Account' : 'New Account'" subtitle="Ledger accounts power your reports and auto-posted entries" />

<div class="max-w-2xl">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <form method="POST" action="{{ $account->exists ? route('management.accounting.accounts.update', $account) : route('management.accounting.accounts.store') }}" class="space-y-5">
            @csrf
            @if($account->exists) @method('PUT') @endif

            @if($errors->any())
            <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Code</label>
                    <input type="text" name="code" value="{{ old('code', $account->code) }}" required placeholder="e.g. 5050"
                        class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Name</label>
                    <input type="text" name="name" value="{{ old('name', $account->name) }}" required placeholder="e.g. Repairs & Maintenance"
                        class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Type</label>
                    <select name="type" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        @foreach($types as $type)
                        <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Parent Account <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select name="parent_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">None</option>
                        @foreach($parents as $parent)
                        <option value="{{ $parent->id }}" @selected(old('parent_id', $account->parent_id) == $parent->id)>{{ $parent->code }} — {{ $parent->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Description <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea name="description" rows="2" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">{{ old('description', $account->description) }}</textarea>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                Active
            </label>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">{{ $account->exists ? 'Save Changes' : 'Create Account' }}</button>
                <a href="{{ route('management.accounting.accounts.index') }}" class="px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
