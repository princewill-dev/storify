@extends('admin.layout')
@section('subtitle', 'Platform Chart of Accounts')

@section('content')
<div class="mb-6">
    <h2 class="text-lg font-bold text-slate-900">Platform Chart of Accounts</h2>
    <p class="text-sm text-slate-500 mt-0.5">Accounts used by Storify's own books.</p>
</div>

@php
    $typeLabels = [
        'asset' => ['Assets', 'bg-emerald-50 text-emerald-700'],
        'liability' => ['Liabilities', 'bg-amber-50 text-amber-700'],
        'equity' => ['Equity', 'bg-indigo-50 text-indigo-700'],
        'income' => ['Income', 'bg-blue-50 text-blue-700'],
        'expense' => ['Expenses', 'bg-red-50 text-red-700'],
    ];
@endphp

<div class="space-y-6">
    @foreach($typeLabels as $type => [$label, $badge])
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3.5 border-b border-slate-100 flex items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">{{ $label }}</span>
            <span class="text-xs text-slate-400">{{ ($accounts[$type] ?? collect())->count() }} account(s)</span>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 bg-slate-50/50">
                <tr>
                    <th class="text-left py-2.5 px-5 font-medium text-slate-600 text-xs uppercase tracking-wider w-24">Code</th>
                    <th class="text-left py-2.5 px-5 font-medium text-slate-600 text-xs uppercase tracking-wider">Account</th>
                    <th class="text-left py-2.5 px-5 font-medium text-slate-600 text-xs uppercase tracking-wider">Subtype</th>
                    <th class="text-center py-2.5 px-5 font-medium text-slate-600 text-xs uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($accounts[$type] ?? [] as $account)
                <tr class="hover:bg-slate-50/50">
                    <td class="py-3 px-5 font-mono text-xs text-slate-500">{{ $account->code }}</td>
                    <td class="py-3 px-5 text-slate-800">{{ $account->name }}</td>
                    <td class="py-3 px-5 text-xs text-slate-400">{{ $account->subtype ?? '—' }}</td>
                    <td class="py-3 px-5 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach
</div>
@endsection
