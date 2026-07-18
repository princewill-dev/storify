@extends('account.layout')
@section('title', 'Dashboard')
@section('subtitle', 'Dashboard')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="stat-icon"><i class="fa-solid fa-bag-shopping"></i></div>
            <div class="stat-value">{{ $stats['total_orders'] }}</div>
            <div class="stat-label">Total Orders</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-value">{{ $stats['pending_orders'] }}</div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-value">{{ $stats['completed_orders'] }}</div>
            <div class="stat-label">Completed</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
            <div class="stat-value">₦{{ number_format($stats['total_spent'], 0) }}</div>
            <div class="stat-label">Total Spent</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
        <h6 class="mb-0 fw-semibold">Recent Orders</h6>
        <a href="{{ route('account.orders') }}" class="text-decoration-none small">View All →</a>
    </div>
    <div class="table-responsive">
        @if($recentOrders->count() > 0)
        <table class="table mb-0">
            <thead><tr>
                <th class="ps-4">Order</th>
                <th>Store</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
                <th class="pe-4"></th>
            </tr></thead>
            <tbody>
                @foreach($recentOrders as $order)
                <tr>
                    <td class="ps-4"><a href="{{ route('account.order.show', $order->order_number) }}" class="fw-semibold text-dark text-decoration-none">{{ $order->order_number }}</a></td>
                    <td class="text-muted">{{ $order->store?->name ?? '—' }}</td>
                    <td>{{ $order->items->count() }}</td>
                    <td class="fw-semibold">₦{{ number_format($order->total, 2) }}</td>
                    <td><span class="badge-status bg-secondary bg-opacity-10 text-secondary">{{ $order->status->label() }}</span></td>
                    <td class="text-muted">{{ $order->created_at->format('M d, Y') }}</td>
                    <td class="pe-4"><a href="{{ route('account.order.show', $order->order_number) }}" class="btn btn-outline btn-sm">View</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="text-center py-5">
            <i class="fa-solid fa-bag-shopping fa-2x text-muted mb-3"></i>
            <p class="text-muted mb-2">No orders yet</p>
            <a href="{{ url('/') }}" class="btn btn-primary btn-sm">Start Shopping</a>
        </div>
        @endif
    </div>
</div>
@endsection
