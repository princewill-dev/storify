@extends('admin.layout')
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
                    <a href="{{ route('admin.transactions.index') }}" class="btn btn-outline-secondary">Back to list</a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
                        Update status
                    </button>
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
                        <a href="{{ route('admin.orders.show', $transaction->order) }}" class="fw-bold">#{{ $transaction->order->order_number }}</a>
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

<!-- Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.transactions.update-status', $transaction) }}">
                @csrf
                @method('PATCH')
                <div class="modal-header">
                    <h5 class="modal-title" id="statusModalLabel">Update Transaction Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            @foreach($statusOptions as $option)
                            <option value="{{ $option->value }}" {{ $transaction->status === $option->value ? 'selected' : '' }}>
                                {{ $option->label() }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <p class="text-muted small">Changing the status will update how this transaction is reported across dashboards.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
