@extends('vendors.layout')
@section('subtitle', 'Order Management')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">
                    Order Management
                    @if(isset($selectedStore))
                        <span class="text-primary small ms-1" style="font-size: 0.6em;">for {{ $selectedStore->name }}</span>
                    @endif
                </h1>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Total Orders</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['total']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Pending</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['pending']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Processing</span>
                    <h2 class="mb-0 fw-bold text-dark">{{ number_format($stats['processing']) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm h-100 bg-light">
                <div class="card-body">
                    <span class="text-uppercase text-muted small fw-semibold d-block mb-1">Total Revenue</span>
                    <h2 class="mb-0 fw-bold text-dark">₦{{ number_format($stats['total_revenue'], 2) }}</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Button -->
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <div>
            @if(request()->hasAny(['search', 'status', 'payment_status', 'store_id', 'date_from', 'date_to']))
                <span class="badge bg-primary me-2">
                    <i class="fi fi-rr-filter"></i> Filters Active
                </span>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fi fi-rr-cross-small"></i> Clear Filters
                </a>
            @endif
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
            <i class="fi fi-rr-filter"></i> Filter Orders
        </button>
    </div>

    <!-- Orders Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Orders ({{ $orders->total() }})</h5>
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
                            <th>Type</th>
                            <th>Total</th>
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
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $order]) }}" class="fw-bold text-decoration-none">
                                        #{{ $order->order_number }}
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div>{{ $order->customer->full_name }}</div>
                                <small class="text-muted">{{ $order->customer->email }}</small>
                            </td>
                            <td>{{ $order->store->name }}</td>
                            <td>{{ $order->items->count() }}</td>
                            <td>
                                @if($order->isShop4me())
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Shop4Me</span>
                                @else
                                    <span class="badge bg-light text-muted border">Standard</span>
                                @endif
                            </td>
                            <td class="fw-bold">₦{{ number_format($order->total, 2) }}</td>
                            <td>
                                @php
                                    $statusColors = [
                                        'pending' => 'secondary',
                                        'accepted' => 'info',
                                        'processing' => 'primary',
                                        'dispatched' => 'secondary',
                                        'delivered' => 'success',
                                        'completed' => 'success',
                                        'cancelled' => 'danger',
                                        'returned' => 'dark',
                                    ];
                                    $color = $statusColors[$order->status] ?? 'secondary';
                                @endphp
                                <span class="badge bg-{{ $color }} text-white-50 text-uppercase fw-semibold" style="font-size: 0.75rem;">{{ ucfirst($order->status) }}</span>
                            </td>
                            <td>
                                @if($order->payment_status === 'paid')
                                <span class="badge bg-success-subtle text-success">Paid</span>
                                @elseif($order->payment_status === 'unpaid')
                                <span class="badge bg-warning-subtle text-warning">Unpaid</span>
                                @elseif($order->payment_status === 'refunded')
                                <span class="badge bg-info-subtle text-info">Refunded</span>
                                @else
                                <span class="badge bg-danger-subtle text-danger">Failed</span>
                                @endif
                            </td>
                            <td>{{ $order->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $order]) }}" class="btn btn-outline-secondary btn-sm" title="View">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    <a href="{{ route('vendor.orders.edit', ['vendor' => $vendor, 'order' => $order]) }}" class="btn btn-outline-secondary btn-sm" title="Edit">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <form action="{{ route('vendor.orders.destroy', ['vendor' => $vendor, 'order' => $order]) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this order?')">
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
                            <td colspan="9" class="text-center py-4">
                                <p class="text-muted mb-0">No orders found</p>
                            </td>
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


    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="filterModalLabel">Filter Orders</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="GET" action="{{ route('admin.orders.index') }}">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Order # or Customer" value="{{ request('search') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Store</label>
                                <select name="store_id" class="form-control">
                                    <option value="">All Stores</option>
                                    @foreach($stores as $store)
                                    <option value="{{ $store->id }}" {{ request('store_id') == $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Order Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                                    <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                                    <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
                                    <option value="dispatched" {{ request('status') == 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                                    <option value="delivered" {{ request('status') == 'delivered' ? 'selected' : '' }}>Delivered</option>
                                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                                    <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                    <option value="returned" {{ request('status') == 'returned' ? 'selected' : '' }}>Returned</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" class="form-control">
                                    <option value="">All</option>
                                    <option value="unpaid" {{ request('payment_status') == 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                    <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                                    <option value="refunded" {{ request('payment_status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
                                    <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date Range</label>
                                <div class="input-group">
                                    <input type="date" name="date_from" class="form-control" placeholder="From" value="{{ request('date_from') }}">
                                    <span class="input-group-text">to</span>
                                    <input type="date" name="date_to" class="form-control" placeholder="To" value="{{ request('date_to') }}">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary">
                            <i class="fi fi-rr-refresh"></i> Clear All
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fi fi-rr-search"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
</div>

@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert('{{ session('success') }}');
    });
</script>
@endif

@if(session('error'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        alert('{{ session('error') }}');
    });
</script>
@endif
@endsection
