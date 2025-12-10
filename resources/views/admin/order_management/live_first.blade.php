@extends('admin.layout')
@section('subtitle', 'Live First Orders')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Live First Orders</h1>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#filterModal">
                <i class="fi fi-rr-filter"></i> Filter Orders
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Total Orders</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['total']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Pending</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['pending']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Processing</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['processing']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Total Revenue</span>
                    <h2 class="mb-0 fw-bold text-dark">₦{{ number_format($stats['total_revenue'], 2) }}</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Filters Notice -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            @if(request()->hasAny(['search', 'status', 'payment_status', 'store_id', 'date_from', 'date_to']))
                <span class="badge bg-secondary text-uppercase fw-semibold me-2">
                    <i class="fi fi-rr-filter"></i> Filters Active
                </span>
                <a href="{{ route('admin.livefirst.orders.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fi fi-rr-cross-small"></i> Clear Filters
                </a>
            @endif
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Live First Orders ({{ $orders->total() }})</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Store</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Down Payment (10%)</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                        <tr>
                            <td>
                                <a href="{{ route('admin.orders.show', $order) }}" class="fw-bold text-decoration-none">
                                    #{{ $order->order_number }}
                                </a>
                                <br>
                                <small class="text-muted"><i class="fa fa-rocket"></i> Live First</small>
                            </td>
                            <td>
                                <div>{{ $order->customer?->full_name ?? 'Guest' }}</div>
                                @if($order->customer?->email)
                                    <small class="text-muted">{{ $order->customer->email }}</small>
                                @endif
                            </td>
                            <td>{{ $order->store?->name ?? '—' }}</td>
                            <td>{{ number_format($order->items_count) }}</td>
                            <td class="fw-bold">₦{{ number_format($order->total, 2) }}</td>
                            <td class="text-success fw-bold">₦{{ number_format($order->total * 0.10, 2) }}</td>
                            <td>
                                <span class="badge {{ $order->status->badgeClass() }} text-white-50 text-uppercase fw-semibold" style="font-size: 0.75rem;">
                                    {{ $order->status->label() }}
                                </span>
                            </td>
                            <td>
                                @php($payment = $order->payment_status ?? 'unpaid')
                                @switch($payment)
                                    @case('paid')
                                        <span class="badge bg-success-subtle text-success">Paid</span>
                                        @break
                                    @case('refunded')
                                        <span class="badge bg-info-subtle text-info">Refunded</span>
                                        @break
                                    @case('failed')
                                        <span class="badge bg-danger-subtle text-danger">Failed</span>
                                        @break
                                    @default
                                        <span class="badge bg-warning-subtle text-warning">Unpaid</span>
                                @endswitch
                            </td>
                            <td>{{ optional($order->created_at)->format('M d, Y') }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-outline-secondary btn-sm" title="View">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.orders.edit', $order) }}" class="btn btn-outline-secondary btn-sm" title="Edit">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.orders.destroy', $order) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this order?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">No Live First orders found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($orders->hasPages())
        <div class="card-footer">
            {{ $orders->links() }}
        </div>
        @endif
    </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="filterModalLabel">Filter Live First Orders</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="GET" action="{{ route('admin.livefirst.orders.index') }}">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Search -->
                        <div class="col-md-12">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="{{ request('search') }}" 
                                   placeholder="Order number, customer name, or email">
                        </div>

                        <!-- Status Filter -->
                        <div class="col-md-6">
                            <label for="status" class="form-label">Order Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                                <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
                                <option value="dispatched" {{ request('status') == 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                                <option value="delivered" {{ request('status') == 'delivered' ? 'selected' : '' }}>Delivered</option>
                                <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                                <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                            </select>
                        </div>

                        <!-- Payment Status Filter -->
                        <div class="col-md-6">
                            <label for="payment_status" class="form-label">Payment Status</label>
                            <select class="form-select" id="payment_status" name="payment_status">
                                <option value="">All Payment Statuses</option>
                                <option value="unpaid" {{ request('payment_status') == 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                                <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
                                <option value="refunded" {{ request('payment_status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
                            </select>
                        </div>

                        <!-- Store Filter -->
                        <div class="col-md-6">
                            <label for="store_id" class="form-label">Store</label>
                            <select class="form-select" id="store_id" name="store_id">
                                <option value="">All Stores</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Date Range -->
                        <div class="col-md-3">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" 
                                   value="{{ request('date_from') }}">
                        </div>

                        <div class="col-md-3">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" 
                                   value="{{ request('date_to') }}">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="{{ route('admin.livefirst.orders.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
