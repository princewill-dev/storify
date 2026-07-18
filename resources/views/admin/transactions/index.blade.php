@extends('admin.layout')
@section('subtitle', 'Transactions')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Transactions</h2>
        <p class="text-sm text-slate-500 mt-0.5">Manage all payment transactions recorded in the platform.</p>
    </div>
    <a href="{{ route('admin.transactions.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reload</a>
</div>

<form method="GET" action="{{ route('admin.transactions.index') }}" class="mb-6">
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Reference</label>
            <input type="text" name="reference" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" placeholder="Search reference" value="{{ request('reference') }}">
        </div>
        <div class="w-full sm:w-48">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Status</label>
            <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All statuses</option>
                @foreach($statusOptions as $option)
                <option value="{{ $option->value }}" {{ request('status') === $option->value ? 'selected' : '' }}>{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Filter</button>
            <a href="{{ route('admin.transactions.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Reset</a>
        </div>
    </div>
</form>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="flex items-center px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-700">Transactions ({{ $transactions->total() }})</h3>
    </div>
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Reference</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Order</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Customer</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Payment Method</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Amount</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($transactions as $transaction)
                <tr class="hover:bg-slate-50/50">
                    <td class="py-3 px-4">
                        <a href="{{ route('admin.transactions.show', $transaction) }}" class="font-semibold text-slate-700 hover:text-slate-900">{{ $transaction->reference }}</a>
                    </td>
                    <td class="py-3 px-4">
                        @if($transaction->order)
                        <a href="{{ route('admin.orders.show', $transaction->order) }}" class="text-slate-600 hover:text-slate-900">{{ $transaction->order->order_number }}</a>
                        @else
                        <span class="text-slate-400">N/A</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if($transaction->order?->customer)
                        <div class="text-slate-700">{{ $transaction->order?->customer?->full_name ?? 'Walk-in' }}</div>
                        <div class="text-xs text-slate-400">{{ $transaction->order?->customer?->email ?? '—' }}</div>
                        @else
                        <span class="text-slate-400">N/A</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if($transaction->paymentMethod)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $transaction->paymentMethod->name }}</span>
                        @else
                        <span class="text-slate-400">N/A</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 font-medium text-slate-700">&#8358;{{ number_format($transaction->amount, 2) }}</td>
                    <td class="py-3 px-4">
                        <span class="{{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                    </td>
                    <td class="py-3 px-4 text-slate-600">{{ $transaction->created_at->format('d M Y h:i A') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-12 text-center text-slate-400">No transactions found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

    @if($transactions->hasPages())
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $transactions->links() }}
    </div>
    @endif
</div>
@endsection
