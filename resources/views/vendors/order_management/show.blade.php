@extends('vendors.layout')
@section('subtitle', 'Order Details - #' . $order->order_number)

@section('content')
<div class="container-fluid order-details">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h1 class="h3 mb-0">ID: #{{ $order->order_number }}</h1>
                    </div>
                    <p class="text-muted mb-0">Date {{ $order->created_at->format('F d, Y \a\t H:i') }}</p>
                </div>
                <div>
                    <a href="{{ route('vendor.orders.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-secondary">
                        <i class="fa fa-arrow-left"></i>
                    </a>
                    <a href="{{ route('vendor.orders.edit', ['vendor' => $vendor, 'order' => $order, 'store_id' => request('store_id')]) }}" class="btn btn-primary">
                        <i class="fa fa-edit"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

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
                                        <span class="badge {{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                                    </p>
                                </div>
                                @endforeach
                            @else
                                <p class="text-muted small mb-0">No payment transactions recorded</p>
                            @endif
                        </div>
                    </div>
                </div>
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
                    <form action="{{ route('vendor.orders.update-status', ['vendor' => $vendor, 'order' => $order]) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted">Current Status</label>
                            <select name="status" class="form-select" required>
                                <option value="pending" {{ $order->status === 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="accepted" {{ $order->status === 'accepted' ? 'selected' : '' }}>Accepted</option>
                                <option value="processing" {{ $order->status === 'processing' ? 'selected' : '' }}>Processing</option>
                                <option value="dispatched" {{ $order->status === 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                                <option value="delivered" {{ $order->status === 'delivered' ? 'selected' : '' }}>Delivered</option>
                                <option value="completed" {{ $order->status === 'completed' ? 'selected' : '' }}>Completed</option>
                                <option value="cancelled" {{ $order->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                <option value="returned" {{ $order->status === 'returned' ? 'selected' : '' }}>Returned</option>
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
                    <form action="{{ route('vendor.orders.update-payment-status', ['vendor' => $vendor, 'order' => $order]) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted">Current Payment Status</label>
                            <select name="payment_status" class="form-select" required>
                                @php
                                    $currentStatus = $order->payment_status;
                                    $statuses = \App\Enums\PaymentStatus::cases();
                                @endphp

                                {{-- Render current status first --}}
                                @if($currentStatus instanceof \App\Enums\PaymentStatus)
                                    <option value="{{ $currentStatus->value }}" selected>{{ $currentStatus->label() }}</option>
                                @endif
                                
                                {{-- Render other statuses --}}
                                @foreach($statuses as $status)
                                    @if($currentStatus instanceof \App\Enums\PaymentStatus && $status === $currentStatus)
                                        @continue
                                    @endif
                                    <option value="{{ $status->value }}" {{ !$currentStatus instanceof \App\Enums\PaymentStatus && $currentStatus == $status->value ? 'selected' : '' }}>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
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
@endsection
