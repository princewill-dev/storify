@extends('home.layout')

@section('content')
<br>
<br>
<br>
<div class="container py-5">
    <!-- Header -->
    <div class="mb-4">
        <a href="{{ route('account.dashboard') }}" class="btn btn-outline-secondary btn-sm mb-3">
            <i class="fi fi-rr-arrow-left"></i> Back to Dashboard
        </a>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2>Bulk Order #{{ $bulkOrder->bulk_code }}</h2>
                <div class="d-flex align-items-center gap-3 mt-2">
                    <span class="badge {{ $bulkOrder->status->badgeClass() }} px-3 py-2">
                        {{ $bulkOrder->status->label() }}
                    </span>
                    <small class="text-muted">Created {{ $bulkOrder->created_at->format('M d, Y') }}</small>
                </div>
            </div>
            @if($canAccept)
            <form action="{{ route('bulk.order.accept', ['store_slug' => $store->slug, 'bulkCode' => $bulkOrder->bulk_code]) }}" method="POST" class="d-inline" id="acceptOrderForm">
                @csrf
                <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#acceptOrderModal">
                    <i class="fi fi-rr-check"></i> Accept Order
                </button>
            </form>
            @endif
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Negotiation Timeline -->
        <div class="col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Negotiation History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <!-- Initial Request -->
                        <div class="list-group-item bg-light">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-secondary">Initial Request</span>
                                <small class="text-muted">{{ $bulkOrder->created_at->format('M d, H:i') }}</small>
                            </div>
                            <p class="mb-1 small text-muted">Customer submitted request</p>
                        </div>

                        @foreach($revisions as $revision)
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

        <!-- Right Column: Current Proposal & Response Form -->
        <div class="col-lg-8">
            <form action="{{ route('bulk.order.respond', ['store_slug' => $store->slug, 'bulkCode' => $bulkOrder->bulk_code]) }}" method="POST">
                @csrf
                
                <!-- Order Items -->
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Current Proposal</h5>
                        <span class="badge bg-info text-white">
                            Last updated by {{ ucfirst($bulkOrder->last_updated_by ?? 'customer') }}
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40%">Product</th>
                                        <th style="width: 20%">Quantity</th>
                                        <th style="width: 20%">Unit Price</th>
                                        <th style="width: 20%" class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($bulkOrder->items as $index => $item)
                                    <tr>
                                        <td>
                                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                                            <div class="d-flex align-items-center">
                                                @if($item->product && $item->product->images && count($item->product->images) > 0)
                                                    <img src="{{ asset('storage/' . $item->product->images[0]->path) }}" 
                                                        alt="{{ $item->product_name }}" 
                                                        class="rounded me-3" 
                                                        width="50" 
                                                        height="50"
                                                        style="object-fit: cover;">
                                                @endif
                                                <div>
                                                    <div class="fw-bold text-truncate" style="max-width: 200px;">{{ $item->product_name }}</div>
                                                    @if($item->is_custom)
                                                        <span class="badge bg-info text-white small">Custom</span>
                                                    @else
                                                        <small class="text-muted">{{ $item->product_code }}</small>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @if($canRespond)
                                                <input type="number" 
                                                       name="items[{{ $index }}][quantity]" 
                                                       class="form-control form-control-sm" 
                                                       value="{{ $item->quantity }}" 
                                                       min="1" 
                                                       required>
                                            @else
                                                <strong>{{ number_format($item->quantity) }}</strong>
                                            @endif
                                        </td>
                                        <td>
                                            @if($item->is_custom)
                                                @if($canRespond)
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₦</span>
                                                        <input type="number" 
                                                               name="items[{{ $index }}][budgeted_amount]" 
                                                               class="form-control" 
                                                               value="{{ $item->budgeted_amount }}" 
                                                               min="0" 
                                                               step="0.01">
                                                    </div>
                                                @else
                                                    ₦{{ number_format($item->budgeted_amount, 2) }}
                                                    <small class="text-muted d-block">Budgeted</small>
                                                @endif
                                            @else
                                                <input type="hidden" name="items[{{ $index }}][unit_price]" value="{{ $item->unit_price }}">
                                                ₦{{ number_format($item->unit_price, 2) }}
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
                                        <td colspan="3" class="text-end fw-bold">Estimated Total:</td>
                                        <td class="text-end fw-bold text-primary fs-5">
                                            ₦{{ number_format($bulkOrder->estimated_total, 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Delivery Information -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">Delivery Address</h6>
                    </div>
                    <div class="card-body">
                        @if($bulkOrder->deliveryAddress)
                            <p class="mb-2"><strong>{{ $bulkOrder->deliveryAddress->recipient_name }}</strong></p>
                            <p class="mb-2">{{ $bulkOrder->deliveryAddress->phone_number }}</p>
                            <p class="mb-0 text-muted">
                                {{ $bulkOrder->deliveryAddress->street_address }}<br>
                                @if($bulkOrder->deliveryAddress->apartment)
                                    {{ $bulkOrder->deliveryAddress->apartment }}<br>
                                @endif
                                {{ $bulkOrder->deliveryAddress->area }}, {{ $bulkOrder->deliveryAddress->state }}
                            </p>
                        @else
                            <p class="text-muted mb-0">No delivery address provided</p>
                        @endif
                    </div>
                </div>

                <!-- Response Section -->
                @if($canRespond)
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Submit Counter-Proposal</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="customer_notes" class="form-label">Add a Note (Optional)</label>
                            <textarea class="form-control" id="customer_notes" name="customer_notes" rows="3" placeholder="Explain your changes or ask questions..."></textarea>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fi fi-rr-paper-plane"></i> Submit Response
                            </button>
                        </div>
                    </div>
                </div>
                @elseif(!$canAccept && !$bulkOrder->customer_accepted_at)
                <div class="alert alert-warning">
                    <i class="fi fi-rr-clock me-2"></i>
                    Waiting for admin response. You can make further changes only after the admin reviews your latest proposal.
                </div>
                @elseif($bulkOrder->customer_accepted_at)
                <div class="alert alert-success">
                    <i class="fi fi-rr-check me-2"></i>
                    You have accepted this order. Please wait for final processing.
                </div>
                @endif
            </form>
        </div>
    </div>
</div>

<!-- Accept Order Confirmation Modal -->
<div class="modal fade" id="acceptOrderModal" tabindex="-1" aria-labelledby="acceptOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="acceptOrderModalLabel">
                    <i class="fi fi-rr-check-circle me-2"></i>Accept Bulk Order
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fi fi-rr-info me-2"></i>
                    <strong>Please confirm you are okay with the following:</strong>
                </div>
                
                <ul class="list-unstyled mb-3">
                    <li class="mb-2">
                        <i class="fi fi-rr-check text-success me-2"></i>
                        <strong>All listed items</strong> in the current proposal
                    </li>
                    <li class="mb-2">
                        <i class="fi fi-rr-check text-success me-2"></i>
                        <strong>Quantities</strong> as specified
                    </li>
                    <li class="mb-2">
                        <i class="fi fi-rr-check text-success me-2"></i>
                        <strong>Estimated total:</strong> <span class="text-primary fw-bold">₦{{ number_format($bulkOrder->estimated_total, 2) }}</span>
                    </li>
                </ul>

                <div class="alert alert-warning mb-0">
                    <i class="fi fi-rr-bulb me-2"></i>
                    By accepting, our team will give your order one final review and send you a payment link to complete your purchase.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fi fi-rr-cross-small"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="document.getElementById('acceptOrderForm').submit();">
                    <i class="fi fi-rr-check"></i> Yes, Accept Order
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
