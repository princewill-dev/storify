@extends('home.layout')
@section('title', 'Transaction Details - ' . $transaction->reference)

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="content-inner-1">
        <div class="container">
            <div class="row">
                <!-- Sidebar -->
                <div class="col-lg-3 col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="avatar avatar-xl bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 32px;">
                                    {{ strtoupper(substr(Auth::guard('customer')->user()->first_name, 0, 1)) }}
                                </div>
                                <h5 class="mt-3 mb-1">{{ Auth::guard('customer')->user()->full_name }}</h5>
                                <p class="text-muted small">{{ Auth::guard('customer')->user()->email }}</p>
                            </div>
                            <nav class="nav flex-column">
                                <a class="nav-link" href="{{ route('account.dashboard') }}">
                                    <i class="fas fa-home me-2"></i> Dashboard
                                </a>
                                <a class="nav-link" href="{{ route('account.info') }}">
                                    <i class="fas fa-user me-2"></i> Account Info
                                </a>
                                <a class="nav-link" href="{{ route('account.orders') }}">
                                    <i class="fas fa-shopping-bag me-2"></i> My Orders
                                </a>
                                <a class="nav-link active" href="{{ route('account.transactions') }}">
                                    <i class="fas fa-credit-card me-2"></i> Transactions
                                </a>
                                <form method="POST" action="{{ route('account.logout') }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="nav-link border-0 bg-transparent text-danger w-100 text-start">
                                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                                    </button>
                                </form>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-lg-9 col-md-8">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <a href="{{ route('account.transactions') }}" class="btn btn-sm btn-outline-secondary mb-2">
                                <i class="fas fa-arrow-left me-2"></i> Back to Transactions
                            </a>
                            <h2 class="mb-0">Transaction Details</h2>
                        </div>
                        <div>
                            <span class="badge {{ $transaction->status->badgeClass() }}">{{ $transaction->status->label() }}</span>
                        </div>
                    </div>

                    <!-- Transaction Summary -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Transaction Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Transaction Reference</p>
                                    <h5 class="mb-0">{{ $transaction->reference }}</h5>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Amount</p>
                                    <h4 class="mb-0 text-primary">₦{{ number_format($transaction->amount, 2) }}</h4>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Payment Method</p>
                                    <p class="mb-0">
                                        @if($transaction->paymentMethod)
                                        <span class="badge bg-light text-dark fs-6">{{ $transaction->paymentMethod->name }}</span>
                                        @else
                                        <span class="text-muted">N/A</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Transaction Date</p>
                                    <p class="mb-0">{{ $transaction->created_at->format('M d, Y h:i A') }}</p>
                                </div>
                                @if($transaction->reference)
                                <div class="col-md-12 mb-3">
                                    <p class="text-muted mb-1">Payment Reference</p>
                                    <p class="mb-0"><code>{{ $transaction->reference }}</code></p>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Related Order -->
                    @if($transaction->order)
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Related Order</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Order Number</p>
                                    <p class="mb-0">
                                        <a href="{{ route('account.order.show', $transaction->order->order_number) }}" class="text-primary">
                                            {{ $transaction->order->order_number }}
                                        </a>
                                    </p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Store</p>
                                    <p class="mb-0">{{ $transaction->order->store->name }}</p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Order Status</p>
                                    <p class="mb-0">
                                        <span class="badge {{ $transaction->order->status->badgeClass() }}">{{ $transaction->order->status->label() }}</span>
                                    </p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Order Date</p>
                                    <p class="mb-0">{{ $transaction->order->created_at->format('M d, Y h:i A') }}</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="{{ route('account.order.show', $transaction->order->order_number) }}" class="btn btn-outline-primary">
                                    <i class="fas fa-eye me-2"></i> View Order Details
                                </a>
                            </div>
                        </div>
                    </div>
                    @endif

                    <!-- Customer Information -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Customer Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Name</p>
                                    <p class="mb-0">{{ $transaction->order->customer->full_name ?? Auth::user()->name }}</p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Email</p>
                                    <p class="mb-0">{{ $transaction->order->customer->email ?? Auth::user()->email }}</p>
                                </div>
                                @if($transaction->order && $transaction->order->customer->phone)
                                <div class="col-md-6 mb-3">
                                    <p class="text-muted mb-1">Phone</p>
                                    <p class="mb-0">{{ $transaction->order->customer->phone }}</p>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Status Info -->
                    @if($transaction->status === 'pending')
                    <div class="alert alert-warning mt-4" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Pending Transaction:</strong> This transaction is still being processed. Please wait for confirmation.
                    </div>
                    @elseif($transaction->status === 'failed')
                    <div class="alert alert-danger mt-4" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Failed Transaction:</strong> This transaction failed. Please try again or contact support if you were charged.
                    </div>
                    @elseif($transaction->status === 'completed')
                    <div class="alert alert-success mt-4" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Successful Transaction:</strong> Your payment has been processed successfully.
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
