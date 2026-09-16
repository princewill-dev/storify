@extends('management.layout')
@section('subtitle', 'Expenses')

@section('content')

<x-management.page-header title="Expenses" subtitle="Record and track business spending">
    <x-slot:actions>
        @can('accounting expenses')
        <a href="{{ route('management.accounting.expenses.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
            <i class="fi fi-rr-plus text-xs"></i> Record Expense
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">This Month</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($totals['month'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">This Year</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($totals['year'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Records</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ $expenses->total() }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <form method="GET" class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2">
        <select name="category" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
            <option value="">All Categories</option>
            @foreach($categories as $category)
            <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
            <option value="">All Statuses</option>
            @foreach(['paid', 'void'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm w-[140px]">
        <input type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm w-[140px]">
        @if(request()->hasAny(['category', 'status', 'from', 'to']))
        <a href="{{ route('management.accounting.expenses.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
        @endif
    </form>

    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Expense</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Category</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Supplier</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($expenses as $expense)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3 text-xs text-slate-500">{{ $expense->expense_date->format('d M Y') }}</td>
                <td class="px-5 py-3">
                    <a href="{{ route('management.accounting.expenses.show', $expense) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $expense->description ?: ($expense->reference ?: 'Expense #'.$expense->id) }}</a>
                    @if($expense->reference)<div class="text-xs text-slate-400">{{ $expense->reference }}</div>@endif
                </td>
                <td class="px-5 py-3 text-slate-600 hidden md:table-cell">{{ $expense->category?->name ?? $expense->ledgerAccount?->name ?? '—' }}</td>
                <td class="px-5 py-3 text-slate-500 hidden lg:table-cell">{{ $expense->supplier?->name ?? '—' }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($expense->total_kobo / 100, 2) }}</td>
                <td class="px-5 py-3 text-center">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $expense->status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600' }}">{{ ucfirst($expense->status) }}</span>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-receipt" title="No expenses recorded" description="Record your first expense to start tracking spending." />
            </td></tr>
            @endforelse
        </tbody>
    </table>

    @if($expenses->hasPages())
    <div class="px-5 py-3 border-t border-slate-100">{{ $expenses->links() }}</div>
    @endif
</div>

@endsection
