@extends('admin.layout')
@section('subtitle', 'Bank Accounts')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Bank Accounts</h2>
    </div>
    <a href="{{ route('admin.bank-accounts.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Add Bank Account</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Logo</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Bank Name</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Account Name</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Account Number</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Sort Order</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($bankAccounts as $account)
                    <tr>
                        <td class="py-3 px-4">
                            @if($account->logo)
                                <img src="{{ Storage::url($account->logo) }}" alt="{{ $account->bank_name }}" class="h-10 w-auto rounded">
                            @else
                                <span class="text-slate-400 text-sm">No logo</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-slate-700">{{ $account->bank_name }}</td>
                        <td class="py-3 px-4 text-slate-700">{{ $account->account_name ?? 'N/A' }}</td>
                        <td class="py-3 px-4"><code class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-600">{{ $account->account_number }}</code></td>
                        <td class="py-3 px-4 text-slate-700">{{ $account->sort_order }}</td>
                        <td class="py-3 px-4">
                            <form method="post" action="{{ route('admin.bank-accounts.toggle-active', $account) }}" class="inline">
                                @csrf
                                <span class="inline-flex items-center rounded-full {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">
                                    {{ $account->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </form>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <form method="post" action="{{ route('admin.bank-accounts.toggle-active', $account) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border {{ $account->is_active ? 'border-amber-200 text-amber-600 hover:bg-amber-50' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50' }}">
                                        {{ $account->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                <a href="{{ route('admin.bank-accounts.edit', $account) }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Edit</a>
                                <button type="button" onclick="openModal('deleteBankAccount{{ $account->id }}')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
                                <x-admin.confirm-modal id="deleteBankAccount{{ $account->id }}" title="Delete Bank Account" message="Delete this bank account?" action="{{ route('admin.bank-accounts.destroy', $account) }}" method="DELETE" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-12 text-center text-slate-400">No bank accounts found. Add one to get started.</td></tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-6 py-4 border-t border-slate-100">
        {{ $bankAccounts->links() }}
    </div>
</div>
@endsection
