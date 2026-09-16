@extends('management.layout')
@section('subtitle', 'Bills')

@section('content')

<x-management.page-header title="Supplier Bills" subtitle="Track what you owe and when it is due">
    <x-slot:actions>
        @can('accounting bills')
        <a href="{{ route('management.accounting.bills.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
            <i class="fi fi-rr-plus text-xs"></i> New Bill
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Outstanding</p>
        <p class="text-xl font-bold text-amber-600 mt-1">₦{{ number_format($stats['open'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Paid (All Time)</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($stats['paid'] / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Overdue</p>
        <p class="text-xl font-bold text-red-600 mt-1">{{ $stats['overdue'] }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <form method="GET" class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Search bill or supplier..." class="flex-1 min-w-[160px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        <select name="supplier" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
            <option value="">All Suppliers</option>
            @foreach($suppliers as $supplier)
            <option value="{{ $supplier->id }}" @selected(request('supplier') == $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-xs shadow-sm">
            <option value="">All Statuses</option>
            @foreach(['open', 'partial', 'paid', 'void'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button class="px-3 py-2 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800">Filter</button>
        @if(request()->hasAny(['q', 'supplier', 'status']))
        <a href="{{ route('management.accounting.bills.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
        @endif
    </form>

    <table class="w-full text-sm">
        <thead class="bg-slate-50/50 border-b border-slate-100">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Bill</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Supplier</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Due</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Balance</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($bills as $bill)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3">
                    <a href="{{ route('management.accounting.bills.show', $bill) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $bill->bill_number }}</a>
                    <div class="text-xs text-slate-400">{{ $bill->issue_date->format('d M Y') }}</div>
                </td>
                <td class="px-5 py-3 text-slate-600">{{ $bill->supplier?->name ?? '—' }}</td>
                <td class="px-5 py-3 text-xs hidden md:table-cell {{ $bill->due_date && $bill->due_date->isPast() && $bill->remainingBalanceKobo() > 0 ? 'text-red-600 font-medium' : 'text-slate-500' }}">{{ $bill->due_date?->format('d M Y') ?? '—' }}</td>
                <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($bill->total_kobo / 100, 2) }}</td>
                <td class="px-5 py-3 text-right hidden sm:table-cell {{ $bill->remainingBalanceKobo() > 0 ? 'text-amber-600 font-semibold' : 'text-slate-500' }}">₦{{ number_format($bill->remainingBalanceKobo() / 100, 2) }}</td>
                <td class="px-5 py-3 text-center">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $bill->status === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($bill->status === 'void' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700') }}">{{ ucfirst($bill->status) }}</span>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-12">
                <x-management.empty-state icon="fi fi-rr-document" title="No bills yet" description="Record supplier bills to track your payables." />
            </td></tr>
            @endforelse
        </tbody>
    </table>

    @if($bills->hasPages())
    <div class="px-5 py-3 border-t border-slate-100">{{ $bills->links() }}</div>
    @endif
</div>

@endsection
