@extends('admin.layout')
@section('subtitle', 'Bulk Order Management')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">Bulk Order Management</h1>
            </div>
        </div>
    </div>

    <!-- Filter Button -->
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <div>
            @if(request()->hasAny(['search', 'status']))
                <span class="badge bg-primary me-2">
                    <i class="fi fi-rr-filter"></i> Filters Active
                </span>
                <a href="{{ route('admin.bulk-orders.index') }}" class="btn btn-sm btn-outline-secondary">
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
            <h5 class="mb-0">Bulk Orders ({{ $bulkOrders->total() }})</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Bulk Code</th>
                            <th>Customer</th>
                            <th>Store</th>
                            <th>Est. Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($bulkOrders as $order)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('admin.bulk-orders.show', $order) }}" class="fw-bold text-decoration-none">
                                        {{ $order->bulk_code }}
                                    </a>
                                </div>
                            </td>
                            <td>
                                <div>{{ $order->customer->full_name }}</div>
                                <small class="text-muted">{{ $order->customer->email }}</small>
                            </td>
                            <td>{{ $order->store->name }}</td>
                            <td class="fw-bold">₦{{ number_format($order->estimated_total, 2) }}</td>
                            <td>
                                <span class="badge {{ $order->status->badgeClass() }} text-white-50 text-uppercase fw-semibold" style="font-size: 0.75rem;">
                                    {{ $order->status->label() }}
                                </span>
                            </td>
                            <td>{{ $order->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ route('admin.bulk-orders.show', $order) }}" class="btn btn-outline-secondary btn-sm" title="View & Edit">
                                        <i class="fa fa-eye"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <p class="text-muted mb-0">No bulk orders found</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($bulkOrders->hasPages())
        <div class="card-footer">
            {{ $bulkOrders->links() }}
        </div>
        @endif
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="filterModalLabel">Filter Bulk Orders</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="GET" action="{{ route('admin.bulk-orders.index') }}">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Bulk Code, Name or Email" value="{{ request('search') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    @foreach($bulkOrderStatuses as $status)
                                        <option value="{{ $status->value }}" {{ request('status') == $status->value ? 'selected' : '' }}>
                                            {{ $status->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="{{ route('admin.bulk-orders.index') }}" class="btn btn-secondary">
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

@endsection
