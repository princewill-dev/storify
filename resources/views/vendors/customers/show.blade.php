@extends('vendors.layout')
@section('subtitle', 'Customer Details')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="{{ route('vendor.customers.index', ['vendor' => $vendor]) }}" class="btn btn-sm btn-secondary mb-2">
                        <i class="fi fi-rr-arrow-left"></i> Back
                    </a>
                    <!-- <h2 class="mb-0">Customer Details</h2> -->
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ route('vendor.customers.edit', ['vendor' => $vendor, 'customer' => $customer]) }}" class="btn btn-outline-secondary">
                        <i class="fi fi-rr-pencil"></i> Edit
                    </a>
                    @if($customer->status === \App\Models\Customer::STATUS_ACTIVE)
                        <button class="btn btn-outline-danger" onclick="showSuspendModal('{{ $customer->account_id }}', '{{ $customer->full_name }}')">
                            <i class="fi fi-rr-ban"></i> Suspend
                        </button>
                    @elseif($customer->status === \App\Models\Customer::STATUS_SUSPENDED)
                        <button class="btn btn-outline-success" onclick="showActivateModal('{{ $customer->account_id }}', '{{ $customer->full_name }}')">
                            <i class="fi fi-rr-check-circle"></i> Activate
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-primary-light me-3">
                        <i class="fi fi-rr-shopping-cart text-primary" style="font-size: 24px;"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Total Orders</h6>
                        <h3 class="mb-0">{{ number_format($stats['total_orders']) }}</h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-success-light me-3">
                        <i class="fi fi-rr-check-circle text-success" style="font-size: 24px;"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Completed</h6>
                        <h3 class="mb-0">{{ number_format($stats['completed_orders']) }}</h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-info-light me-3">
                        <i class="fi fi-rr-money text-info" style="font-size: 24px;"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Total Spent</h6>
                        <h3 class="mb-0">₦{{ number_format($stats['total_spent'], 2) }}</h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-warning-light me-3">
                        <i class="fi fi-rr-time-fast text-warning" style="font-size: 24px;"></i>
                    </div>
                    <div>
                        <h6 class="mb-1">Pending</h6>
                        <h3 class="mb-0">{{ number_format($stats['pending_orders']) }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="detail-grid">
        <div class="detail-column">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Customer Information</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="avatar-circle-lg mx-auto mb-3">
                            {{ strtoupper(substr($customer->first_name, 0, 1)) }}{{ strtoupper(substr($customer->last_name, 0, 1)) }}
                        </div>
                        <h4>{{ $customer->full_name }}</h4>
                        <span class="badge bg-light text-dark border">Status: {{ ucfirst(strtolower($customer->status)) }}</span>
                    </div>

                    <div class="info-list">
                        <div class="info-item">
                            <i class="fi fi-rr-envelope"></i>
                            <div>
                                <small class="text-muted">Email</small>
                                <p class="mb-0">{{ $customer->email }}</p>
                            </div>
                        </div>
                        @if($customer->phone)
                        <div class="info-item">
                            <i class="fi fi-rr-phone-call"></i>
                            <div>
                                <small class="text-muted">Phone</small>
                                <p class="mb-0">{{ $customer->phone }}</p>
                            </div>
                        </div>
                        @endif
                        @if($customer->company_name)
                        <div class="info-item">
                            <i class="fi fi-rr-building"></i>
                            <div>
                                <small class="text-muted">Company</small>
                                <p class="mb-0">{{ $customer->company_name }}</p>
                            </div>
                        </div>
                        @endif
                        <div class="info-item">
                            <i class="fi fi-rr-calendar"></i>
                            <div>
                                <small class="text-muted">Member Since</small>
                                <p class="mb-0">{{ $customer->created_at->format('M d, Y') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Address Information</h4>
                </div>
                <div class="card-body">
                    @if($customer->street_address)
                    <p class="mb-2"><strong>Street Address:</strong><br>{{ $customer->street_address }}</p>
                    @if($customer->apartment)
                    <p class="mb-2"><strong>Apartment/Unit:</strong><br>{{ $customer->apartment }}</p>
                    @endif
                    <p class="mb-2"><strong>City:</strong> {{ $customer->city ?? '-' }}</p>
                    <p class="mb-2"><strong>State:</strong> {{ $customer->state ?? '-' }}</p>
                    <p class="mb-2"><strong>ZIP Code:</strong> {{ $customer->zip_code ?? '-' }}</p>
                    <p class="mb-0"><strong>Country:</strong> {{ $customer->country ?? '-' }}</p>
                    @else
                    <p class="text-muted text-center">No address information available</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="detail-column">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Recent Orders</h4>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Store</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($customer->orders as $order)
                                <tr>
                                    <td>
                                        <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $order]) }}">
                                            {{ $order->order_number }}
                                        </a>
                                    </td>
                                    <td>{{ $order->store->name }}</td>
                                    <td>{{ $order->items->count() }} items</td>
                                    <td>₦{{ number_format($order->total, 2) }}</td>
                                    <td>
                                        @php
                                            $statusColors = [
                                                'pending' => 'warning',
                                                'accepted' => 'info',
                                                'processing' => 'primary',
                                                'dispatched' => 'info',
                                                'delivered' => 'success',
                                                'completed' => 'success',
                                                'cancelled' => 'danger',
                                                'returned' => 'secondary',
                                            ];
                                            $color = $statusColors[$order->status] ?? 'secondary';
                                        @endphp
                                        <span class="badge bg-{{ $color }}">{{ ucfirst($order->status) }}</span>
                                    </td>
                                    <td>{{ $order->created_at->format('M d, Y') }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No orders yet
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Recent Transactions</h4>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Payment Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($transactions as $transaction)
                                <tr>
                                    <td>
                                        <!-- <a href="{{ route('admin.orders.show', ['order' => $transaction->order_number]) }}">
                                            {{ $transaction->reference }}
                                        </a> -->
                                        {{ $transaction->reference }}
                                    </td>
                                    <td>{{ $transaction->payment_method_name }}</td>
                                    <td>₦{{ number_format($transaction->amount, 2) }}</td>
                                    <td>
                                        @if($transaction->status === 'completed')
                                        <span class="badge bg-success">Completed</span>
                                        @elseif($transaction->status === 'pending')
                                        <span class="badge bg-warning">Pending</span>
                                        @elseif($transaction->status === 'failed')
                                        <span class="badge bg-danger">Failed</span>
                                        @else
                                        <span class="badge bg-secondary">{{ ucfirst($transaction->status) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($transaction->created_at)->format('M d, Y') }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        No transactions yet
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Activity Log</h4>
                </div>
                <div class="card-body">
                    @forelse($activityLogs as $log)
                    <div class="activity-item">
                        <div class="activity-icon">
                            <i class="fi fi-rr-time-past"></i>
                        </div>
                        <div class="activity-content">
                            <p class="mb-1">
                                <strong>{{ $log->user ? $log->user->name : 'System' }}</strong>
                                {{ $log->description }}
                            </p>
                            <small class="text-muted">
                                {{ $log->created_at->diffForHumans() }}
                                ({{ $log->created_at->format('M d, Y H:i') }})
                            </small>
                        </div>
                    </div>
                    @empty
                    <p class="text-muted text-center">No activity recorded</p>
                    @endforelse
                </div>
            </div>
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
.customer-details-page {
    max-width: 960px;
    margin: 0 auto;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.detail-grid {
    display: grid;
    grid-template-columns: minmax(250px, 300px) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}
.detail-column {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.customer-details-page .card {
    border-radius: 14px;
    border-color: rgba(15, 23, 42, 0.08);
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
}
.stats-grid .card { min-height: 96px; }
.stats-grid .card-body { padding: 14px 16px; }
.stats-grid .icon-box {
    width: 40px;
    height: 40px;
}
.stats-grid h3 { font-size: 18px; }
.stats-grid h6 {
    font-size: 12px;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}
.avatar-circle-lg {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #007bff;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 28px;
}
.icon-box {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card-body.p-0 table { margin-bottom: 0; }
.card-header {
    border-bottom: none;
    padding-bottom: 0;
}
.card-header .card-title {
    font-size: 15px;
    font-weight: 600;
}
.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-item { display: flex; gap: 12px; align-items: flex-start; }
.info-item i { font-size: 18px; color: #007bff; margin-top: 4px; }
.activity-item { display: flex; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
.activity-item:last-child { border-bottom: none; }
.activity-icon { width: 36px; height: 36px; border-radius: 50%; background: #f8fafc; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.activity-icon i { font-size: 16px; color: #64748b; }
.activity-content { flex: 1; }
.bg-primary-light { background: rgba(0, 123, 255, 0.08); }
.bg-success-light { background: rgba(40, 167, 69, 0.1); }
.bg-info-light { background: rgba(23, 162, 184, 0.1); }
.bg-warning-light { background: rgba(255, 193, 7, 0.12); }

@media (max-width: 1400px) {
    .customer-details-page { max-width: 100%; }
}

@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }
    .stats-grid .card-body { padding: 14px; }
}

@media (max-width: 992px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
const vendorId = '{{ $vendor->id }}';

function showSuspendModal(accountId, customerName) {
    document.getElementById('suspendCustomerName').textContent = customerName;
    document.getElementById('suspendForm').action = `/vendor/${vendorId}/customers/${accountId}/suspend`;
    new bootstrap.Modal(document.getElementById('suspendModal')).show();
}

function showActivateModal(accountId, customerName) {
    document.getElementById('activateCustomerName').textContent = customerName;
    document.getElementById('activateForm').action = `/vendor/${vendorId}/customers/${accountId}/activate`;
    new bootstrap.Modal(document.getElementById('activateModal')).show();
}
</script>
@endsection
