@extends('management.layout')
@section('subtitle', 'Chart of Accounts')

@section('content')

<x-management.page-header title="Chart of Accounts" subtitle="All ledger accounts for your business">
    <x-slot:actions>
        @can('accounting accounts')
        <a href="{{ route('management.accounting.accounts.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
            <i class="fi fi-rr-plus text-xs"></i> Add Account
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

@php
    $typeLabels = [
        'asset' => ['Assets', 'text-emerald-700 bg-emerald-50'],
        'liability' => ['Liabilities', 'text-amber-700 bg-amber-50'],
        'equity' => ['Equity', 'text-indigo-700 bg-indigo-50'],
        'income' => ['Income', 'text-blue-700 bg-blue-50'],
        'expense' => ['Expenses', 'text-red-700 bg-red-50'],
    ];
@endphp

<div class="space-y-6">
    @foreach($typeLabels as $type => [$label, $badge])
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">{{ $label }}</span>
            <span class="text-xs text-slate-400">{{ ($grouped[$type] ?? collect())->count() }} account(s)</span>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50/50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-24">Code</th>
                    <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Account</th>
                    <th class="px-5 py-2.5 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Subtype</th>
                    <th class="px-5 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Balance</th>
                    <th class="px-5 py-2.5 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-2.5 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($grouped[$type] ?? [] as $account)
                @php
                    $row = $balances[$account->id] ?? null;
                    $debit = (int) ($row->debit ?? 0);
                    $credit = (int) ($row->credit ?? 0);
                    $balance = in_array($account->type, ['asset', 'expense']) ? $debit - $credit : $credit - $debit;
                @endphp
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $account->code }}</td>
                    <td class="px-5 py-3">
                        <span class="font-medium text-slate-800">{{ $account->name }}</span>
                        @if($account->is_system)<span class="ml-2 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">System</span>@endif
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-400 hidden md:table-cell">{{ $account->subtype ?? '—' }}</td>
                    <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($balance / 100, 2) }}</td>
                    <td class="px-5 py-3 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-1.5">
                            @can('accounting accounts')
                            <a href="{{ route('management.accounting.accounts.edit', $account) }}" class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Edit</a>
                            <form method="POST" action="{{ route('management.accounting.accounts.toggle', $account) }}">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium {{ $account->is_active ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' }} rounded-md">{{ $account->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-5 py-6 text-center text-xs text-slate-400">No accounts in this group.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endforeach
</div>

@endsection
