@extends('home.layout')
@section('title', 'My Account')

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
                                    {{ strtoupper(substr($customer->first_name, 0, 1)) }}
                                </div>
                                <h5 class="mt-3 mb-1">{{ $customer->full_name }}</h5>
                                <p class="text-muted small">{{ $customer->email }}</p>
                            </div>
                            <nav class="nav flex-column">
                                <a class="nav-link active" href="{{ route('account.dashboard') }}">
                                    <i class="fas fa-home me-2"></i> Dashboard
                                </a>
                                <a class="nav-link" href="{{ route('account.info') }}">
                                    <i class="fas fa-user me-2"></i> Account Info
                                </a>
                                <a class="nav-link" href="{{ route('account.orders') }}">
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
                    <h2 class="mb-4">Dashboard</h2>

                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-shopping-bag fa-2x text-primary mb-2"></i>
                                    <h3 class="mb-0">{{ $stats['total_orders'] }}</h3>
                                    <p class="text-muted small mb-0">Total Orders</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                                    <h3 class="mb-0">{{ $stats['pending_orders'] }}</h3>
                                    <p class="text-muted small mb-0">Pending</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <h3 class="mb-0">{{ $stats['completed_orders'] }}</h3>
                                    <p class="text-muted small mb-0">Completed</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-wallet fa-2x text-info mb-2"></i>
                                    <h3 class="mb-0">₦{{ number_format($stats['total_spent'], 2) }}</h3>
                                    <p class="text-muted small mb-0">Total Spent</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Orders -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Orders</h5>
                            <a href="{{ route('account.orders') }}" class="btn btn-sm btn-primary">View All</a>
                        </div>
                        <div class="card-body">
                            @if($recentOrders->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Order #</th>
                                                <th>Store</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($recentOrders as $order)
                                            <tr>
                                                <td><strong>{{ $order->order_number }}</strong></td>
                                                <td>{{ $order->store->name }}</td>
                                                <td>{{ $order->items->count() }} item(s)</td>
                                                <td>₦{{ number_format($order->total, 2) }}</td>
                                                <td>
                                                    <span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span>
                                                </td>
                                                <td>{{ $order->created_at->format('M d, Y') }}</td>
                                                <td>
                                                    <a href="{{ route('account.order.show', $order->order_number) }}" class="btn btn-sm btn-outline-primary">View</a>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="text-center py-5">
                                    <i class="fas fa-shopping-bag fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No orders yet</p>
                                    <a href="{{ url('/') }}" class="btn btn-primary">Start Shopping</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
