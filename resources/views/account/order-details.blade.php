@extends('home.layout')
@section('title', 'Order Details - ' . $order->order_number)

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
                                    {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                                </div>
                                <h5 class="mt-3 mb-1">{{ Auth::user()->name }}</h5>
                                <p class="text-muted small">{{ Auth::user()->email }}</p>
                            </div>
                            <nav class="nav flex-column">
                                <a class="nav-link" href="{{ route('account.dashboard') }}">
                                    <i class="fas fa-home me-2"></i> Dashboard
                                </a>
                                <a class="nav-link" href="{{ route('account.info') }}">
                                    <i class="fas fa-user me-2"></i> Account Info
                                </a>
                                <a class="nav-link active" href="{{ route('account.orders') }}">
                                    <i class="fas fa-shopping-bag me-2"></i> My Orders
                                </a>
                                <a class="nav-link" href="{{ route('account.transactions') }}">
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
                            <a href="{{ route('account.orders') }}" class="btn btn-sm btn-outline-secondary mb-2">
                                <i class="fas fa-arrow-left me-2"></i> Back to Orders
                            </a>
                            <h2 class="mb-0">Order #{{ $order->order_number }}</h2>
                        </div>
                        <div>
                            <span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span>
                        </div>
                    </div>

                    <!-- Order Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Order Information</h5>
                                </div>
                                <div class="card-body">
                                    <p class="mb-2"><strong>Order Date:</strong> {{ $order->created_at->format('M d, Y h:i A') }}</p>
                                    <p class="mb-2"><strong>Store:</strong> {{ $order->store->name }}</p>
                                    <p class="mb-2"><strong>Payment Status:</strong> 
                                        @if($order->transactions->where('status', 'completed')->count() > 0)
                                            <span class="badge bg-success">Paid</span>
                                        @else
                                            <span class="badge bg-warning">Pending</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Delivery Address</h5>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1">{{ $order->delivery_first_name }} {{ $order->delivery_last_name }}</p>
                                    <p class="mb-1">{{ $order->delivery_street_address }}</p>
                                    @if($order->delivery_apartment)
                                    <p class="mb-1">{{ $order->delivery_apartment }}</p>
                                    @endif
                                    <p class="mb-1">{{ $order->delivery_city }}, {{ $order->delivery_state }} {{ $order->delivery_zip_code }}</p>
                                    <p class="mb-0">{{ $order->delivery_country }}</p>
                                    @if($order->delivery_phone)
                                    <p class="mb-0 mt-2"><strong>Phone:</strong> {{ $order->delivery_phone }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Order Items</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Price</th>
                                            <th>Quantity</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($order->items as $item)
                                        <tr>
                                            <td>
                                                <strong>{{ $item->product->name }}</strong>
                                                @if($item->product_variant_id)
                                                <br><small class="text-muted">Variant ID: {{ $item->product_variant_id }}</small>
                                                @endif
                                            </td>
                                            <td>₦{{ number_format($item->unit_price, 2) }}</td>
                                            <td>{{ $item->quantity }}</td>
                                            <td class="text-end">₦{{ number_format($item->subtotal, 2) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Subtotal:</strong></td>
                                            <td class="text-end">₦{{ number_format($order->subtotal, 2) }}</td>
                                        </tr>
                                        @if($order->shipping_fee > 0)
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Delivery Fee:</strong></td>
                                            <td class="text-end">₦{{ number_format($order->shipping_fee, 2) }}</td>
                                        </tr>
                                        @endif
                                        @if($order->tax > 0)
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Tax:</strong></td>
                                            <td class="text-end">₦{{ number_format($order->tax, 2) }}</td>
                                        </tr>
                                        @endif
                                        <tr class="table-active">
                                            <td colspan="3" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong class="text-primary fs-5">₦{{ number_format($order->total, 2) }}</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Transactions -->
                    @if($order->transactions->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Payment Transactions</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Transaction ID</th>
                                            <th>Payment Method</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($order->transactions as $transaction)
                                        <tr>
                                            <td>
                                                <a href="{{ route('account.transaction.show', ['transactionId' => $transaction->reference]) }}" class="text-primary">
                                                    {{ $transaction->reference }}
                                                </a>
                                            </td>
                                            <td>{{ $transaction->paymentMethod->name ?? 'N/A' }}</td>
                                            <td>₦{{ number_format($transaction->amount, 2) }}</td>
                                            <td>
                                                @if($transaction->status === 'completed')
                                                    <span class="badge bg-success">Completed</span>
                                                @elseif($transaction->status === 'pending')
                                                    <span class="badge bg-warning">Pending</span>
                                                @elseif($transaction->status === 'failed')
                                                    <span class="badge bg-danger">Failed</span>
                                                @else
                                                    <span class="badge {{ $transaction->status->badgeClass() }}">{{ $transaction->status->label() }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $transaction->created_at->format('M d, Y h:i A') }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
