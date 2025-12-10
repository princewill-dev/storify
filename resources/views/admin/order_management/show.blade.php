@extends('admin.layout')
@section('subtitle', 'Order Details - #' . $order->order_number)

@section('content')
<div class="container-fluid order-details">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h1 class="h3 mb-0">Order #{{ $order->order_number }}</h1>
                        @if($order->isShop4me())
                            <span class="badge bg-dark">Shop4Me</span>
                        @elseif($order->source === 'live_first')
                            <span class="badge bg-success"><i class="fa fa-rocket me-1"></i>Live First</span>
                        @else
                            <span class="badge bg-secondary">Standard</span>
                        @endif
                    </div>
                    <p class="text-muted mb-0">Created {{ $order->created_at->format('F d, Y \a\t H:i') }}</p>
                </div>
                <div>
                    <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary">
                        <i class="fa fa-arrow-left"></i> Back to Orders
                    </a>
                    <a href="{{ route('admin.orders.edit', $order) }}" class="btn btn-primary">
                        <i class="fa fa-edit"></i> Edit Order
                    </a>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Scoped to this page only */
        .order-details .card { height: auto !important; }
        .order-details .equal > [class*='col-'] { display: flex; }
        .order-details .equal .card { height: 100%; width: 100%; }
        .order-summary-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            align-items: center;
            font-size: 0.95rem;
        }
        .order-summary-inline .summary-divider {
            color: #cbd5e1;
        }
        .order-summary-inline .total {
            font-size: 1.05rem;
        }
        @media (max-width: 576px) {
            .order-summary-inline {
                justify-content: center;
                text-align: center;
            }
        }
    </style>

    <div class="row g-3 g-lg-4">
        <!-- Left Column -->
        <div class="col-lg-8">
            <!-- Order Items -->
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">Order Items ({{ $order->items->count() }})</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>Code</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($order->items as $item)
                                <tr>
                                    <td>
                                        <div class="fw-bold">{{ $item->product_name }}</div>
                                        @if($item->product)
                                        <small class="text-muted">ID: {{ $item->product_id }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $item->product_code }}</td>
                                    <td>₦{{ number_format($item->unit_price, 2) }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td class="fw-bold">₦{{ number_format($item->subtotal, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="5">
                                        <div class="order-summary-inline">
                                            <span><strong>Subtotal:</strong> ₦{{ number_format($order->subtotal, 2) }}</span>
                                            <span class="summary-divider">|</span>
                                            <span><strong>Shipping:</strong> ₦{{ number_format($order->shipping_fee, 2) }}</span>
                                            <span class="summary-divider">|</span>
                                            <span><strong>Tax:</strong> ₦{{ number_format($order->tax, 2) }}</span>
                                            <span class="summary-divider">|</span>
                                            <span class="total"><strong>Total:</strong> ₦{{ number_format($order->total, 2) }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Customer & Delivery -->
            <div class="row g-3 equal align-items-stretch">
                <div class="col-md-6">
                    <div class="card h-100 mb-3 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <h6 class="mb-0 fw-semibold">Customer Information</h6>
                        </div>
                        <div class="card-body p-2">
                            <p class="mb-2 small"><span class="text-muted">Name:</span><br><strong>{{ $order->customer->full_name }}</strong></p>
                            <p class="mb-2 small"><span class="text-muted">Email:</span><br><strong>{{ $order->customer->email }}</strong></p>
                            <p class="mb-2 small"><span class="text-muted">Phone:</span><br><strong>{{ $order->customer->phone }}</strong></p>
                            <p class="mb-0 small"><span class="text-muted">Address:</span><br><strong>{{ $order->customer->full_address }}</strong></p>
                        </div>
                    </div>
                </div>
                @if($order->delivery_state || $order->deliveryRoute)
                <div class="col-md-6">
                    <div class="card h-100 mb-3 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <h6 class="mb-0 fw-semibold">Delivery Information</h6>
                        </div>
                        <div class="card-body p-2">
                            @if($order->delivery_state && $order->delivery_area)
                            <p class="mb-2 small"><span class="text-muted">Delivery Location:</span><br><strong>{{ $order->delivery_area }}, {{ $order->delivery_state }}</strong></p>
                            @endif
                            @if($order->deliveryRoute)
                            <p class="mb-2 small"><span class="text-muted">Delivery Route:</span><br><strong>{{ $order->deliveryRoute->name }}</strong></p>
                            <p class="mb-0 small"><span class="text-muted">Estimated Delivery:</span><br><strong>{{ $order->deliveryRoute->delivery_days }} days</strong></p>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Payment & Notes -->
            <div class="row g-3 equal align-items-stretch">
                <div class="col-md-6">
                    <div class="card h-100 mb-3 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <h6 class="mb-0 fw-semibold">Payment Information</h6>
                        </div>
                        <div class="card-body p-2">
                            @if($order->transactions->isNotEmpty())
                                @foreach($order->transactions as $transaction)
                                <div class="mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                                    <p class="mb-2 small"><span class="text-muted">Reference:</span><br><strong>{{ $transaction->reference }}</strong></p>
                                    <p class="mb-2 small"><span class="text-muted">Payment Method:</span><br><strong>{{ $transaction->paymentMethod->name ?? 'N/A' }}</strong></p>
                                    <p class="mb-2 small"><span class="text-muted">Amount:</span><br><strong>₦{{ number_format($transaction->amount, 2) }}</strong></p>
                                    <p class="mb-0 small"><span class="text-muted">Status:</span><br>
                                        <span class="badge {{ $transaction->status->badgeClass() }}">{{ $transaction->status->label() }}</span>
                                    </p>
                                </div>
                                @endforeach
                            @else
                                <p class="text-muted small mb-0">No payment transactions recorded</p>
                            @endif
                        </div>
                    </div>
                </div>

                @if($order->source === 'live_first')
                <div class="col-md-6">
                    <div class="card h-100 mb-3 border-0 shadow-sm border-success">
                        <div class="card-header bg-success-subtle border-bottom border-success">
                            <h6 class="mb-0 fw-semibold text-success"><i class="fa fa-rocket me-2"></i>Live First Payment Plan</h6>
                        </div>
                        <div class="card-body p-3">
                            @php
                                $downPayment = $order->meta['down_payment'] ?? 0;
                                $balance = $order->meta['balance'] ?? 0;
                                $monthlyPayment = $order->meta['monthly_payment'] ?? 0;
                                $paymentPlanMonths = $order->meta['payment_plan_months'] ?? 6;
                            @endphp
                            <div class="mb-3">
                                <p class="mb-1 small text-muted">Total Order Amount</p>
                                <p class="mb-0 fs-5 fw-bold">₦{{ number_format($order->total, 2) }}</p>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <p class="mb-1 small text-muted">Down Payment (10%)</p>
                                <p class="mb-0 fw-bold text-success">₦{{ number_format($downPayment, 2) }}</p>
                                <small class="text-muted">
                                    @if($order->transactions->isNotEmpty())
                                        Status: <span class="badge {{ $order->transactions->first()->status->badgeClass() }}">{{ $order->transactions->first()->status->label() }}</span>
                                    @endif
                                </small>
                            </div>
                            <div class="mb-3">
                                <p class="mb-1 small text-muted">Balance (90%)</p>
                                <p class="mb-0 fw-bold">₦{{ number_format($balance, 2) }}</p>
                                <small class="text-muted">To be paid over {{ $paymentPlanMonths }} months</small>
                            </div>
                            <div class="mb-0">
                                <p class="mb-1 small text-muted">Monthly Deduction</p>
                                <p class="mb-0 fw-bold text-primary">₦{{ number_format($monthlyPayment, 2) }}</p>
                                <small class="text-muted">Automatic salary deduction</small>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                @if($order->notes)
                <div class="col-md-6">
                    <div class="card h-100 mb-3 border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <h6 class="mb-0 fw-semibold">Order Notes</h6>
                        </div>
                        <div class="card-body p-2">
                            <p class="mb-0 small" style="white-space: pre-wrap;">{{ $order->notes }}</p>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Activity Log -->
            @if($activityLogs->isNotEmpty())
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">Activity Log</h6>
                </div>
                <div class="card-body p-2" style="max-height: 360px; overflow:auto;">
                    <div class="timeline">
                        @foreach($activityLogs as $log)
                        <div class="timeline-item mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div class="d-flex">
                                <div class="flex-shrink-0">
                                    <i class="fa fa-circle text-secondary" style="font-size: 6px;"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <p class="mb-1 small"><strong>{{ $log->user->name ?? 'System' }}</strong> {{ $log->description }}</p>
                                    <small class="text-muted" style="font-size: 11px;">{{ $log->created_at->diffForHumans() }}</small>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">
            <!-- Status Management -->
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">Order Status</h6>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.orders.update-status', $order) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted">Current Status</label>
                            <select name="status" class="form-select" required>
                                @foreach(\App\Enums\OrderStatus::cases() as $status)
                                    <option value="{{ $status->value }}" {{ $order->status === $status ? 'selected' : '' }}>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Add notes about this status change..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-dark btn-sm w-100">
                            <i class="fa fa-save"></i> Update Status
                        </button>
                    </form>
                </div>
            </div>

            <!-- Payment Status -->
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">Payment Status</h6>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.orders.update-payment-status', $order) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted">Current Payment Status</label>
                            <select name="payment_status" class="form-select" required>
                                <option value="unpaid" {{ $order->payment_status === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                <option value="paid" {{ $order->payment_status === 'paid' ? 'selected' : '' }}>Paid</option>
                                <option value="refunded" {{ $order->payment_status === 'refunded' ? 'selected' : '' }}>Refunded</option>
                                <option value="failed" {{ $order->payment_status === 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-dark btn-sm w-100">
                            <i class="fa fa-save"></i> Update Payment
                        </button>
                    </form>
                </div>
            </div>

            <!-- Quick Info -->
            <div class="card mb-3 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0 fw-semibold">Quick Info</h6>
                </div>
                <div class="card-body">
                    <p class="mb-2 small"><span class="text-muted">Store:</span><br><strong>{{ $order->store->name }}</strong></p>
                    @if($order->vendor)
                    <p class="mb-2 small"><span class="text-muted">Vendor:</span><br><strong>{{ $order->vendor->name }}</strong></p>
                    @endif
                    <p class="mb-2 small"><span class="text-muted">Order Date:</span><br><strong>{{ $order->created_at->format('M d, Y H:i') }}</strong></p>
                    <p class="mb-0 small"><span class="text-muted">Last Updated:</span><br><strong>{{ $order->updated_at->diffForHumans() }}</strong></p>
                </div>
            </div>
        </div>
    </div>
</div>
    </div>
</div>

<!-- Bulk Order Finalized Modal -->
@if(session('bulk_finalized'))
<div class="modal fade" id="bulkFinalizedModal" tabindex="-1" aria-labelledby="bulkFinalizedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="bulkFinalizedModalLabel">
                    <i class="fa fa-check-circle me-2"></i>Bulk Order Finalized Successfully
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Bulk order has been finalized and converted to Order 
                    <a href="{{ route('admin.orders.show', session('bulk_finalized')['order_number']) }}" class="fw-bold text-primary">
                        #{{ session('bulk_finalized')['order_number'] }}
                    </a>
                </p>
                <p class="mb-0">
                    Payment link has been sent to the customer:
                </p>
                <div class="input-group mt-2">
                    <input type="text" class="form-control form-control-sm" id="paymentLinkInput" value="{{ session('bulk_finalized')['payment_link'] }}" readonly>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="copyPaymentLink()">
                        <i class="fa fa-copy"></i> Copy
                    </button>
                </div>
                <a href="{{ session('bulk_finalized')['payment_link'] }}" target="_blank" class="btn btn-sm btn-link mt-2">
                    <i class="fa fa-external-link-alt"></i> Open Payment Link
                </a>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var bulkModal = new bootstrap.Modal(document.getElementById('bulkFinalizedModal'));
    bulkModal.show();
});

function copyPaymentLink() {
    var input = document.getElementById('paymentLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);
    
    var btn = event.target.closest('button');
    var originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
    setTimeout(() => {
        btn.innerHTML = originalHTML;
    }, 2000);
}
</script>
@endif

@endsection
