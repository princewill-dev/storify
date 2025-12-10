@extends('admin.layout')

@section('subtitle', 'Family Pack Requests')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Family Pack Requests</h1>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Requests</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['total'] }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-box fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Review</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['pending'] }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-hourglass-half fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Subscriptions</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['active'] }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-sync fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Completed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $stats['completed'] }}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#familyPackFilterModal">
            <i class="fas fa-filter mr-1"></i> Filter Requests
        </button>
        @if(request()->hasAny(['search','status','pack_type','store_id']))
            <a href="{{ route('admin.family-packs.index') }}" class="btn btn-secondary">
                <i class="fas fa-times mr-1"></i> Clear
            </a>
        @endif
    </div>
    <div class="modal fade" id="familyPackFilterModal" tabindex="-1" aria-labelledby="familyPackFilterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="familyPackFilterModalLabel">Filter Requests</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('admin.family-packs.index') }}" method="GET">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Pack Code, Email..." value="{{ request('search') }}">
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
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    @foreach(\App\Enums\FamilyPackStatus::cases() as $status)
                                        <option value="{{ $status->value }}" {{ request('status') == $status->value ? 'selected' : '' }}>
                                            {{ $status->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <select name="pack_type" class="form-control">
                                    <option value="">All Types</option>
                                    <option value="single" {{ request('pack_type') == 'single' ? 'selected' : '' }}>Single Purchase</option>
                                    <option value="recurring" {{ request('pack_type') == 'recurring' ? 'selected' : '' }}>Recurring Subscription</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter mr-1"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Requests List</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Pack Code</th>
                            <th>Customer</th>
                            <th>Store</th>
                            <!-- <th>Type</th> -->
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.family-packs.show-by-code', $order->pack_code) }}" class="font-weight-bold">
                                        {{ $order->pack_code }}
                                    </a>
                                </td>
                                <td>
                                    <div>{{ $order->customer->first_name }} {{ $order->customer->last_name }}</div>
                                    <small class="text-muted">{{ $order->customer->email }}</small>
                                </td>
                                <td>{{ $order->store->name }}</td>
                                <!-- <td>
                                    @if($order->pack_type === 'recurring')
                                        <span class="badge badge-info">Recurring</span>
                                    @else
                                        <span class="badge badge-secondary">Single</span>
                                    @endif
                                </td> -->
                                <td>
                                    <span class="badge {{ $order->status->badgeClass() }}">
                                        {{ $order->status->label() }}
                                    </span>
                                </td>
                                <td>{{ $order->created_at->format('M d, Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('admin.family-packs.show-by-code', $order->pack_code) }}" class="btn btn-primary btn-sm">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="text-gray-500">No requests found matching your criteria.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $orders->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
