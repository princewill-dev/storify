@extends('management.layout')
@section('subtitle', $supplier->name)

@section('content')

<x-management.page-header :title="$supplier->name" :subtitle="$supplier->email ?? $supplier->phone ?? 'Supplier'">
    <x-slot:actions>
        <a href="{{ route('management.accounting.bills.create') }}" class="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">New Bill</a>
        <a href="{{ route('management.accounting.suppliers.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Back</a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Bills</h2></div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Bill</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Balance</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($supplier->bills as $bill)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-3"><a href="{{ route('management.accounting.bills.show', $bill) }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $bill->bill_number }}</a></td>
                        <td class="px-5 py-3 text-xs text-slate-500">{{ $bill->issue_date->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($bill->total_kobo / 100, 2) }}</td>
                        <td class="px-5 py-3 text-right {{ $bill->remainingBalanceKobo() > 0 ? 'text-amber-600 font-semibold' : 'text-slate-500' }}">₦{{ number_format($bill->remainingBalanceKobo() / 100, 2) }}</td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $bill->status === 'paid' ? 'bg-emerald-50 text-emerald-700' : ($bill->status === 'void' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700') }}">{{ ucfirst($bill->status) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-xs text-slate-400">No bills for this supplier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100"><h2 class="text-sm font-semibold text-slate-800">Payments</h2></div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50/50 border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Method</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($supplier->billPayments as $payment)
                    <tr>
                        <td class="px-5 py-3 text-xs text-slate-500">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $payment->reference ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ ucfirst(str_replace('_', ' ', $payment->method ?? '—')) }}</td>
                        <td class="px-5 py-3 text-right font-semibold text-slate-800">₦{{ number_format($payment->amount_kobo / 100, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-5 py-8 text-center text-xs text-slate-400">No payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h2 class="text-sm font-semibold text-slate-800 mb-4">Contact</h2>
        <dl class="space-y-3 text-sm">
            <div><dt class="text-slate-500 text-xs">Email</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $supplier->email ?? '—' }}</dd></div>
            <div><dt class="text-slate-500 text-xs">Phone</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $supplier->phone ?? '—' }}</dd></div>
            <div><dt class="text-slate-500 text-xs">Address</dt><dd class="font-medium text-slate-800 mt-0.5">{{ $supplier->address ?? '—' }}</dd></div>
            @if($supplier->notes)<div><dt class="text-slate-500 text-xs">Notes</dt><dd class="text-slate-700 mt-0.5">{{ $supplier->notes }}</dd></div>@endif
        </dl>
    </div>
</div>

@endsection
