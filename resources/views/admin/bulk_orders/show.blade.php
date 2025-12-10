@extends('admin.layout')
@section('subtitle', 'View Bulk Order')

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Bulk Order #{{ $bulkOrder->bulk_code }}</h1>
                    <p class="text-muted mb-0">Created on {{ $bulkOrder->created_at->format('M d, Y h:i A') }}</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.bulk-orders.index') }}" class="btn btn-outline-secondary">
                        <i class="fi fi-rr-arrow-left"></i> Back to List
                    </a>
                    
                    @if($canFinalize)
                        <!-- Finalize Button Trigger Modal -->
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#finalizeModal">
                            <i class="fi fi-rr-check"></i> Finalize Order
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-md-8">
            <!-- Items Card -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Order Items</h5>
                    <span class="badge {{ $bulkOrder->status->badgeClass() }}">
                        {{ $bulkOrder->status->label() }}
                    </span>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.bulk-orders.update', $bulkOrder) }}" method="POST" id="updateForm">
                        @csrf
                        @method('PUT')
                        
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40%">Product / Description</th>
                                        <th style="width: 15%">Quantity</th>
                                        <th style="width: 40%">Unit Price / Budget</th>
                                        <th style="width: 20%">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($bulkOrder->items as $index => $item)
                                    <tr>
                                        <td>
                                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                                            
                                            @if($item->is_custom)
                                                <span class="badge bg-info mb-1">Custom Item</span>
                                                <div class="fw-bold">{{ $item->product_name }}</div>
                                            @else
                                                <div class="d-flex align-items-center">
                                                    @if($item->product && $item->product->images && count($item->product->images) > 0)
                                                        <img src="{{ asset('storage/' . $item->product->images[0]->path) }}" alt="" class="rounded me-2" width="40" height="40">
                                                    @endif
                                                    <div>
                                                        <div class="fw-bold">{{ $item->product_name }}</div>
                                                        <small class="text-muted">{{ $item->product_code }}</small>
                                                    </div>
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="number" name="items[{{ $index }}][quantity]" class="form-control" value="{{ $item->quantity }}" min="1" required>
                                        </td>
                                        <td>
                                            @if($item->is_custom)
                                                <div class="input-group">
                                                    <span class="input-group-text">₦</span>
                                                    <input type="number" name="items[{{ $index }}][budgeted_amount]" class="form-control" value="{{ $item->budgeted_amount }}" min="0" step="0.01">
                                                </div>
                                                <small class="text-muted d-block mt-1">Budgeted Amount</small>
                                            @else
                                                <div class="input-group">
                                                    <span class="input-group-text">₦</span>
                                                    <input type="number" name="items[{{ $index }}][unit_price]" class="form-control" value="{{ $item->unit_price }}" min="0" step="0.01">
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-end fw-bold">
                                            ₦{{ number_format($item->subtotal, 2) }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-light">

                                    <tr>
                                        <td colspan="4">
                                            <div class="order-summary-inline">
                                                <span><strong>Subtotal:</strong> ₦{{ number_format($bulkOrder->subtotal, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span><strong>Shipping:</strong> ₦{{ number_format($shippingFee, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span><strong>Tax ({{ number_format($vatPercentage, 1) }}%):</strong> ₦{{ number_format($tax, 2) }}</span>
                                                <span class="summary-divider">|</span>
                                                <span class="total"><strong>Total:</strong> ₦{{ number_format($bulkOrder->estimated_total, 2) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="mb-3 mt-4">
                            <label class="form-label fw-bold">Customer Notes</label>
                            <div class="p-3 bg-light rounded border">
                                {{ $bulkOrder->notes ?: 'No notes provided.' }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Admin Review Notes (Visible to Customer)</label>
                            <textarea name="admin_notes" class="form-control" rows="3" placeholder="Add notes about price adjustments or availability...">{{ $bulkOrder->review_notes }}</textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" name="action" value="notify" class="btn btn-primary">
                                <i class="fi fi-rr-paper-plane"></i> Save & Notify Customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Negotiation History -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Negotiation History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                        <!-- Initial Request -->
                        <div class="list-group-item bg-light">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-secondary">Initial Request</span>
                                <small class="text-muted">{{ $bulkOrder->created_at->format('M d, H:i') }}</small>
                            </div>
                            <p class="mb-1 small text-muted">Customer submitted request</p>
                        </div>

                        @foreach(\App\Models\BulkOrderRevision::where('bulk_order_id', $bulkOrder->id)->orderBy('revision_number', 'asc')->get() as $revision)
                        <div class="list-group-item {{ $revision->created_by_type === 'admin' ? 'bg-white border-start border-4 border-primary' : 'bg-light border-start border-4 border-secondary' }}">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge {{ $revision->created_by_type === 'admin' ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ ucfirst($revision->created_by_type) }} Response
                                </span>
                                <small class="text-muted">{{ $revision->created_at->format('M d, H:i') }}</small>
                            </div>
                            
                            @if($revision->notes)
                            <div class="alert {{ $revision->created_by_type === 'admin' ? 'alert-primary' : 'alert-secondary' }} py-2 px-3 mb-2 small">
                                {{ $revision->notes }}
                            </div>
                            @endif

                            <div class="d-flex justify-content-between align-items-center small">
                                <span class="text-muted">Total Amount:</span>
                                <span class="fw-bold">₦{{ number_format($revision->total_amount, 2) }}</span>
                            </div>
                            
                            @if($revision->is_customer_accepted)
                            <div class="mt-2 text-center">
                                <span class="badge bg-success"><i class="fi fi-rr-check"></i> Accepted</span>
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar Info -->
        <div class="col-md-4">

            <!-- Customer Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Customer Details</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar avatar-md bg-primary text-white rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-size: 1.2rem;">
                            {{ substr($bulkOrder->customer->first_name, 0, 1) }}
                        </div>
                        <div>
                            <h6 class="mb-0">{{ $bulkOrder->customer->full_name }}</h6>
                            <small class="text-muted">Customer</small>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <i class="fi fi-rr-envelope me-2 text-muted"></i>
                        <a href="mailto:{{ $bulkOrder->customer->email }}" class="text-decoration-none">{{ $bulkOrder->customer->email }}</a>
                    </div>
                    <div class="mb-2">
                        <i class="fi fi-rr-phone-call me-2 text-muted"></i>
                        <a href="tel:{{ $bulkOrder->customer->phone }}" class="text-decoration-none">{{ $bulkOrder->customer->phone }}</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Delivery Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Delivery Information</h5>
                </div>
                <div class="card-body">
                    @if($bulkOrder->deliveryAddress)
                        <h6 class="mb-1">{{ $bulkOrder->deliveryAddress->recipient_name }}</h6>
                        <p class="mb-2 text-muted">{{ $bulkOrder->deliveryAddress->recipient_phone }}</p>
                        <p class="mb-3">
                            {{ $bulkOrder->deliveryAddress->street_address }}<br>
                            @if($bulkOrder->deliveryAddress->apartment)
                                {{ $bulkOrder->deliveryAddress->apartment }}<br>
                            @endif
                        </p>
                        
                        @if($bulkOrder->deliveryRoute)
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted d-block">Delivery Route</small>
                                <strong>{{ $bulkOrder->deliveryRoute->area }}, {{ $bulkOrder->deliveryRoute->state }}</strong>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-6">
                                    <small class="text-muted d-block">Delivery Fee</small>
                                    <strong class="text-primary">₦{{ number_format($bulkOrder->deliveryRoute->fee / 100, 2) }}</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Delivery Time</small>
                                    <strong>{{ $bulkOrder->deliveryRoute->delivery_days }} day(s)</strong>
                                </div>
                            </div>
                        @endif
                    @else
                        <p class="text-muted mb-0">No delivery address selected.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Store Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Store Information</h5>
                </div>
                <div class="card-body">
                    <h6 class="mb-1">{{ $bulkOrder->store->name }}</h6>
                    <p class="text-muted mb-0">{{ $bulkOrder->store->description }}</p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Finalize Modal -->
<div class="modal fade" id="finalizeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Finalize Bulk Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to finalize this bulk order?</p>
                <p>This action will:</p>
                <ul>
                    <li>Create a regular system order.</li>
                    <li>Mark this bulk order as <strong>Completed</strong>.</li>
                    <li>Send an email to the customer with payment instructions.</li>
                </ul>
                <div class="alert alert-warning">
                    <i class="fi fi-rr-exclamation"></i> Ensure all prices and quantities are final before proceeding.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('admin.bulk-orders.finalize', $bulkOrder) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success">Confirm Finalize</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
