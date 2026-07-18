@extends('admin.layout')
@section('subtitle', 'Transaction ' . $transaction->reference)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Transaction {{ $transaction->reference }}</h2>
        <p class="text-sm text-slate-500 mt-0.5">View full details for this payment event.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('admin.transactions.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Back to list</a>
        <button type="button" onclick="openModal('statusModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update status</button>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="flex items-center px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Summary</h3>
        </div>
        <div class="p-6">
            <div class="flex justify-between mb-4">
                <span class="text-slate-500 text-sm">Reference</span>
                <strong class="text-slate-900">{{ $transaction->reference }}</strong>
            </div>
            <hr class="border-slate-100 mb-4">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <p class="text-sm text-slate-500 mb-1">Status</p>
                    <span class="{{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                </div>
                <div class="text-right">
                    <p class="text-sm text-slate-500 mb-1">Amount</p>
                    <strong class="text-emerald-600 text-lg">&#8358;{{ number_format($transaction->amount, 2) }}</strong>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-slate-500 mb-0.5">Payment method</p>
                    <p class="text-slate-700">{{ $transaction->paymentMethod->name ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-0.5">Gateway Ref.</p>
                    <p class="text-slate-700">{{ $transaction->gateway_reference ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-0.5">Created</p>
                    <p class="text-slate-700">{{ $transaction->created_at->format('d M Y h:i A') }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 mb-0.5">Paid at</p>
                    <p class="text-slate-700">{{ optional($transaction->paid_at)?->format('d M Y h:i A') ?? 'Pending' }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="flex items-center px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Order &amp; Customer</h3>
        </div>
        <div class="p-6">
            <p class="text-sm text-slate-500 mb-1">Order</p>
            @if($transaction->order)
            <p class="mb-4">
                <a href="{{ route('admin.orders.show', $transaction->order) }}" class="font-semibold text-slate-700 hover:text-slate-900">#{{ $transaction->order->order_number }}</a>
                @if($transaction->order->store)
                <span class="text-slate-400">({{ $transaction->order?->store?->name ?? '—' }})</span>
                @endif
            </p>
            @else
            <p class="text-slate-400 mb-4">Not attached to an order</p>
            @endif

            <hr class="border-slate-100 mb-4">

            <p class="text-sm text-slate-500 mb-1">Customer</p>
            @if($transaction->order?->customer)
            <p class="text-slate-700 mb-0.5">{{ $transaction->order?->customer?->full_name ?? 'Walk-in' }}</p>
            <p class="text-sm text-slate-400">{{ $transaction->order?->customer?->email ?? '—' }}</p>
            @else
            <p class="text-slate-400">N/A</p>
            @endif
        </div>
    </div>

    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="flex items-center px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Gateway Response</h3>
        </div>
        <div class="p-6">
            <pre class="text-sm text-slate-600 whitespace-pre-wrap break-words font-mono bg-slate-50 rounded-lg p-4">{{ $transaction->gateway_response ?? 'No response captured.' }}</pre>
        </div>
    </div>
</div>

<div id="statusModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="statusModalLabel" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('statusModal')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl">
            <form method="POST" action="{{ route('admin.transactions.update-status', $transaction) }}">
                @csrf
                @method('PATCH')
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-base font-semibold text-slate-900" id="statusModalLabel">Update Transaction Status</h3>
                    <button type="button" onclick="closeModal('statusModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>
                <div class="p-6">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Status</label>
                    <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        @foreach($statusOptions as $option)
                        <option value="{{ $option->value }}" {{ $transaction->status === $option->value ? 'selected' : '' }}>
                            {{ $option->label() }}
                        </option>
                        @endforeach
                    </select>
                    <p class="text-sm text-slate-400 mt-3">Changing the status will update how this transaction is reported across dashboards.</p>
                </div>
                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                    <button type="button" onclick="closeModal('statusModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
