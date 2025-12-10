@extends('admin.layout')
@section('subtitle', 'Customers')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="mb-0">Customer Management</h2>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-box bg-primary-light me-3">
                            <i class="fi fi-rr-users-alt text-primary" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Total Customers</h6>
                            <h3 class="mb-0">{{ number_format($stats['total']) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-box bg-success-light me-3">
                            <i class="fi fi-rr-check-circle text-success" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Active</h6>
                            <h3 class="mb-0">{{ number_format($stats['active']) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-box bg-danger-light me-3">
                            <i class="fi fi-rr-ban text-danger" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Suspended</h6>
                            <h3 class="mb-0">{{ number_format($stats['suspended']) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-box bg-info-light me-3">
                            <i class="fi fi-rr-shopping-cart text-info" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Total Orders</h6>
                            <h3 class="mb-0">{{ number_format($stats['total_orders']) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Button -->
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <div>
            @if(request()->hasAny(['search', 'status', 'country']))
                <span class="badge bg-primary me-2">
                    <i class="fi fi-rr-filter"></i> Filters Active
                </span>
                <a href="{{ route('admin.customers.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fi fi-rr-cross-small"></i> Clear Filters
                </a>
            @endif
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
            <i class="fi fi-rr-filter"></i> Filter Customers
        </button>
    </div>

    <!-- Customers Table -->
    <div class="card">
        <div class="card-header">
            <h4 class="card-title mb-0">All Customers</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Location</th>
                            <th>Orders</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-2">
                                        {{ strtoupper(substr($customer->first_name, 0, 1)) }}{{ strtoupper(substr($customer->last_name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <strong>{{ $customer->full_name }}</strong>
                                        @if($customer->company_name)
                                        <br><small class="text-muted">{{ $customer->company_name }}</small>
                                        @endif
                                        <div class="small text-muted">{{ $customer->account_id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $customer->email }}</td>
                            <td>{{ $customer->phone ?? '-' }}</td>
                            <td>{{ $customer->location ?? '-' }}</td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $customer->orders_count }} orders</span>
                            </td>
                            <td>
                                @php($customerBadge = $statusBadgeData[$customer->status] ?? null)
                                <span class="badge {{ $customerBadge['class'] ?? 'bg-secondary' }} border">
                                    {{ $customerBadge['label'] ?? ucfirst(strtolower($customer->status)) }}
                                </span>
                            </td>
                            <td>{{ $customer->created_at->format('M d, Y') }}</td>
                            <td>
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-sm border-0 bg-transparent text-dark" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Customer actions">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="{{ route('admin.customers.show', $customer) }}">
                                                <i class="fa fa-eye me-2 text-muted"></i>View
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="{{ route('admin.customers.edit', $customer) }}">
                                                <i class="fa fa-edit me-2 text-muted"></i>Edit
                                            </a>
                                        </li>
                                        <li>
                                            @if($customer->status === \App\Models\Customer::STATUS_ACTIVE)
                                                <button class="dropdown-item d-flex align-items-center" type="button"
                                                        onclick="showSuspendModal('{{ $customer->account_id }}', '{{ $customer->full_name }}')">
                                                    <i class="fa fa-ban me-2 text-muted"></i>Suspend
                                                </button>
                                            @elseif($customer->status === \App\Models\Customer::STATUS_SUSPENDED)
                                                <button class="dropdown-item d-flex align-items-center" type="button"
                                                        onclick="showActivateModal('{{ $customer->account_id }}', '{{ $customer->full_name }}')">
                                                    <i class="fa fa-check me-2 text-muted"></i>Activate
                                                </button>
                                            @endif
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="fi fi-rr-users-alt" style="font-size: 48px; color: #ccc;"></i>
                                <p class="text-muted mt-2">No customers found</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($customers->hasPages())
        <div class="card-footer">
            {{ $customers->links() }}
        </div>
        @endif
    </div>
</div>


<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="filterModalLabel">Filter Customers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="GET" action="{{ route('admin.customers.index') }}">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, email, phone, or account ID..." value="{{ request('search') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="ACTIVE" {{ request('status') === 'ACTIVE' ? 'selected' : '' }}>Active</option>
                                <option value="SUSPENDED" {{ request('status') === 'SUSPENDED' ? 'selected' : '' }}>Suspended</option>
                                <option value="DELETED" {{ request('status') === 'DELETED' ? 'selected' : '' }}>Deleted</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country</label>
                            <select name="country" class="form-control">
                                <option value="">All Countries</option>
                                @foreach($countries as $country)
                                <option value="{{ $country }}" {{ request('country') === $country ? 'selected' : '' }}>{{ $country }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="{{ route('admin.customers.index') }}" class="btn btn-secondary">
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

<!-- Suspend Modal -->
<div class="modal fade" id="suspendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="suspendForm" method="POST">
                @csrf
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fi fi-rr-ban"></i> Suspend Customer Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <strong>⚠️ Warning:</strong> This will prevent the customer from accessing their account and placing new orders.
                    </div>
                    <p>You are about to suspend the account for:</p>
                    <p class="text-center"><strong id="suspendCustomerName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Reason for Suspension *</label>
                        <textarea name="reason" class="form-control" rows="4" required 
                                  placeholder="Please provide a detailed reason for suspending this account..."></textarea>
                        <small class="text-muted">The customer will receive an email with this reason.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fi fi-rr-ban"></i> Suspend Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Activate Modal -->
<div class="modal fade" id="activateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="activateForm" method="POST">
                @csrf
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fi fi-rr-check-circle"></i> Activate Customer Account
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        <strong>✓ Confirmation:</strong> This will restore full access to the customer's account.
                    </div>
                    <p>You are about to activate the account for:</p>
                    <p class="text-center"><strong id="activateCustomerName"></strong></p>
                    <p class="text-muted">The customer will receive an email notification confirming their account has been activated.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fi fi-rr-check-circle"></i> Activate Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #007bff;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
}
.icon-box {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.bg-primary-light { background: rgba(0, 123, 255, 0.1); }
.bg-success-light { background: rgba(40, 167, 69, 0.1); }
.bg-danger-light { background: rgba(220, 53, 69, 0.1); }
.bg-info-light { background: rgba(23, 162, 184, 0.1); }
.badge.bg-info { background-color: rgba(0,0,0,0.05); color: #212529; }
</style>

<script>
function showSuspendModal(accountId, customerName) {
    document.getElementById('suspendCustomerName').textContent = customerName;
    document.getElementById('suspendForm').action = `/superadmin/customers/${accountId}/suspend`;
    new bootstrap.Modal(document.getElementById('suspendModal')).show();
}

function showActivateModal(accountId, customerName) {
    document.getElementById('activateCustomerName').textContent = customerName;
    document.getElementById('activateForm').action = `/superadmin/customers/${accountId}/activate`;
    new bootstrap.Modal(document.getElementById('activateModal')).show();
}
</script>
@endsection
