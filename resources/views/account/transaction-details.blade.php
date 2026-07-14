@extends('account.layout')
@section('title', 'Transaction ' . $transaction->reference)
@section('subtitle', $transaction->reference)

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Transaction Details</h6>
                <span class="badge-status bg-secondary bg-opacity-10 text-secondary">{{ $transaction->status->label() }}</span>
            </div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-6"><small class="text-muted d-block">Amount</small><span class="fw-bold fs-5">₦{{ number_format($transaction->amount, 2) }}</span></div>
                    <div class="col-6"><small class="text-muted d-block">Currency</small><span>{{ $transaction->currency }}</span></div>
                    <div class="col-6"><small class="text-muted d-block">Reference</small><span class="font-monospace small">{{ $transaction->reference }}</span></div>
                    <div class="col-6"><small class="text-muted d-block">Method</small><span>{{ $transaction->paymentMethod?->name ?? '—' }}</span></div>
                    <div class="col-6"><small class="text-muted d-block">Date</small><span class="small">{{ $transaction->created_at->format('M d, Y H:i') }}</span></div>
                    @if($transaction->paid_at)<div class="col-6"><small class="text-muted d-block">Paid At</small><span class="small text-success">{{ \Carbon\Carbon::parse($transaction->paid_at)->format('M d, Y H:i') }}</span></div>@endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        @if($transaction->order)
        <div class="card mb-3">
            <div class="px-4 py-3 border-bottom"><h6 class="mb-0 fw-semibold">Order</h6></div>
            <div class="p-4">
                <a href="{{ route('account.order.show', $transaction->order->order_number) }}" class="fw-semibold text-dark text-decoration-none">{{ $transaction->order->order_number }}</a>
                <p class="mb-0 small text-muted mt-1">{{ $transaction->order->items->count() }} item(s) · ₦{{ number_format($transaction->order->total, 2) }}</p>
            </div>
        </div>
        @endif
        <a href="{{ route('account.transactions') }}" class="btn btn-outline btn-sm w-100">← Back to Transactions</a>
    </div>
</div>
@endsection
