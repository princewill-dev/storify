@extends('vendors.layout')
@section('subtitle', 'Transaction ' . $transaction->reference)

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Transaction {{ $transaction->reference }}</h1>
                    <p class="text-muted">View full details for this payment event.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" class="btn btn-outline-secondary">Back to list</a>
                </div>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Reference</span>
                        <strong>{{ $transaction->reference }}</strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <p class="text-muted mb-0">Status</p>
                            <span class="badge {{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                        </div>
                        <div class="text-end">
                            <p class="text-muted mb-0">Amount</p>
                            <strong class="text-success">₦{{ number_format($transaction->amount, 2) }}</strong>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <p class="text-muted mb-1">Payment method</p>
                            <p class="mb-0">{{ $transaction->paymentMethod->name ?? 'N/A' }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Gateway Ref.</p>
                            <p class="mb-0">{{ $transaction->gateway_reference ?? 'N/A' }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Created</p>
                            <p class="mb-0">{{ $transaction->created_at->format('d M Y h:i A') }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Paid at</p>
                            <p class="mb-0">{{ optional($transaction->paid_at)?->format('d M Y h:i A') ?? 'Pending' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Order & Customer</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-1">Order</p>
                    @if($transaction->order)
                    <p>
                        <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $transaction->order]) }}" class="fw-bold">#{{ $transaction->order->order_number }}</a>
                        @if($transaction->order->store)
                        <span class="text-muted">({{ $transaction->order->store->name }})</span>
                        @endif
                    </p>
                    @else
                    <p class="text-muted">Not attached to an order</p>
                    @endif

                    <hr>

                    <p class="text-muted mb-1">Customer</p>
                    @if($transaction->order && $transaction->order->customer)
                    <p class="mb-1">{{ $transaction->order->customer->full_name }}</p>
                    <p class="text-muted mb-0">{{ $transaction->order->customer->email }}</p>
                    @else
                    <p class="text-muted">N/A</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Gateway Response</h5>
                </div>
                <div class="card-body">
                    <pre class="mb-0 text-sm text-muted" style="white-space: pre-wrap; word-break: break-word;">{{ $transaction->gateway_response ?? 'No response captured.' }}</pre>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
