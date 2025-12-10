@extends('vendors.layout')
@section('subtitle', 'Transactions')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Transactions</h1>
                    <p class="text-muted">Manage all payment transactions recorded in the platform.</p>
                </div>
                <a href="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" class="btn btn-outline-secondary">Reload</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
    @endif

    <form method="GET" action="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" class="mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" placeholder="Search reference" value="{{ request('reference') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    @foreach($statusOptions as $option)
                    <option value="{{ $option->value }}" {{ request('status') === $option->value ? 'selected' : '' }}>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
                <a href="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Transactions ({{ $transactions->total() }})</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Payment Method</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $transaction)
                        <tr>
                            <td>
                                <a href="{{ route('vendor.transactions.show', ['vendor' => $vendor, 'transaction' => $transaction]) }}" class="fw-bold text-decoration-none">{{ $transaction->reference }}</a>
                            </td>
                            <td>
                                @if($transaction->order)
                                <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $transaction->order]) }}">{{ $transaction->order->order_number }}</a>
                                @else
                                <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($transaction->order && $transaction->order->customer)
                                <div>{{ $transaction->order->customer->full_name }}</div>
                                <small class="text-muted">{{ $transaction->order->customer->email }}</small>
                                @else
                                <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($transaction->paymentMethod)
                                <span class="badge bg-light text-dark">{{ $transaction->paymentMethod->name }}</span>
                                @else
                                <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>₦{{ number_format($transaction->amount, 2) }}</td>
                            <td>
                                <span class="badge {{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                            </td>
                            <td>{{ $transaction->created_at->format('d M Y h:i A') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                No transactions found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($transactions->hasPages())
        <div class="card-footer">
            {{ $transactions->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
