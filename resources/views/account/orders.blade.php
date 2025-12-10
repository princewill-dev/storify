@extends('home.layout')
@section('title', 'My Orders')

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
                        <h2 class="mb-0">My Orders</h2>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" action="{{ route('account.orders') }}" class="row g-3">
                                <div class="col-md-4">
                                    <input type="text" name="search" class="form-control" placeholder="Search by order number" value="{{ request('search') }}">
                                </div>
                                <div class="col-md-4">
                                    <select name="status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Processing</option>
                                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-filter me-2"></i> Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Orders List -->
                    @if($orders->count() > 0)
                        @foreach($orders as $order)
                        <div class="card mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Order #{{ $order->order_number }}</strong>
                                    <span class="text-muted">Placed on {{ $order->created_at->format('M d, Y') }}</span>
                                    @if($order->isShop4me())
                                        <span class="badge bg-info ms-2">Shop4Me</span>
                                    @else
                                        <span class="badge bg-secondary ms-2">Standard</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <p class="mb-2"><strong>Store:</strong> {{ $order->store->name }}</p>
                                        <p class="mb-2"><strong>Items:</strong> {{ $order->items->count() }} item(s)</p>
                                        <div class="mb-2">
                                            <strong>Products:</strong>
                                            <ul class="list-unstyled ms-3 mb-0">
                                                @foreach($order->items->take(3) as $item)
                                                <li>{{ $item->product->name }} (x{{ $item->quantity }})</li>
                                                @endforeach
                                                @if($order->items->count() > 3)
                                                <li class="text-muted">... and {{ $order->items->count() - 3 }} more</li>
                                                @endif
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <h4 class="text-primary mb-3">₦{{ number_format($order->total, 2) }}</h4>
                                        <a href="{{ route('account.order.show', $order->order_number) }}" class="btn btn-outline-primary">
                                            <i class="fas fa-eye me-2"></i> View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        <!-- Pagination -->
                        <div class="d-flex justify-content-center">
                            {{ $orders->links() }}
                        </div>
                    @else
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-shopping-bag fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No orders found</h5>
                                <p class="text-muted">You haven't placed any orders yet.</p>
                                <a href="{{ url('/') }}" class="btn btn-primary">Start Shopping</a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
