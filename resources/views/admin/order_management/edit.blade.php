@extends('admin.layout')
@section('subtitle', 'Edit Order - #' . $order->order_number)

@section('content')


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


<div class="container-fluid order-details">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">Edit Order #{{ $order->order_number }}</h1>
                <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-secondary">
                    <i class="fa fa-arrow-left"></i> Back to Order
                </a>
            </div>
        </div>
    </div>

    @if($errors->any())
    <div class="alert alert-danger">
        <h6>Please fix the following errors:</h6>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form action="{{ route('admin.orders.update', $order) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Order Items (Read-only) -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Order Items</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="py-2">Product</th>
                                        <th class="py-2">Code</th>
                                        <th class="py-2">Price</th>
                                        <th class="py-2">Qty</th>
                                        <th class="py-2">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($order->items as $item)
                                    <tr>
                                        <td class="py-2">{{ $item->product_name }}</td>
                                        <td class="py-2">{{ $item->product_code }}</td>
                                        <td class="py-2">₦{{ number_format($item->unit_price, 2) }}</td>
                                        <td class="py-2">{{ $item->quantity }}</td>
                                        <td class="py-2">₦{{ number_format($item->subtotal, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Pricing</h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Subtotal (Read-only)</label>
                                <input type="text" class="form-control form-control-sm" value="₦{{ number_format($order->subtotal, 2) }}" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Shipping Fee</label>
                                <input type="number" name="shipping_fee" class="form-control form-control-sm" step="0.01" value="{{ old('shipping_fee', $order->shipping_fee) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Tax</label>
                                <input type="number" name="tax" class="form-control form-control-sm" step="0.01" value="{{ old('tax', $order->tax) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Total (Calculated)</label>
                                <input type="text" class="form-control form-control-sm" value="₦{{ number_format($order->total, 2) }}" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Notes -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Order Notes</h6>
                    </div>
                    <div class="card-body p-3">
                        <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="Add any notes about this order...">{{ old('notes', $order->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Status -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Status</h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-2">
                            <label class="form-label small mb-1">Order Status</label>
                            <select name="status" class="form-select form-select-sm">
                                @foreach(\App\Enums\OrderStatus::cases() as $status)
                                    <option value="{{ $status->value }}" {{ old('status', $order->status) == $status->value ? 'selected' : '' }}>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small mb-1">Payment Status</label>
                            <select name="payment_status" class="form-select form-select-sm">
                                <option value="unpaid" {{ old('payment_status', $order->payment_status) === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                <option value="paid" {{ old('payment_status', $order->payment_status) === 'paid' ? 'selected' : '' }}>Paid</option>
                                <option value="refunded" {{ old('payment_status', $order->payment_status) === 'refunded' ? 'selected' : '' }}>Refunded</option>
                                <option value="failed" {{ old('payment_status', $order->payment_status) === 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Customer Info (Read-only) -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Customer</h6>
                    </div>
                    <div class="card-body p-3">
                        <p class="mb-2 small"><strong>Name:</strong><br>{{ $order->customer->full_name }}</p>
                        <p class="mb-2 small"><strong>Email:</strong><br>{{ $order->customer->email }}</p>
                        <p class="mb-0 small"><strong>Phone:</strong><br>{{ $order->customer->phone }}</p>
                    </div>
                </div>

                <!-- Delivery Info (Read-only) -->
                @if($order->delivery_state || $order->deliveryRoute)
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Delivery</h6>
                    </div>
                    <div class="card-body p-3">
                        @if($order->delivery_state && $order->delivery_area)
                        <p class="mb-2 small"><strong>Location:</strong><br>{{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                        @endif
                        @if($order->deliveryRoute)
                        <p class="mb-2 small"><strong>Route:</strong><br>{{ $order->deliveryRoute->name }}</p>
                        <p class="mb-0 small"><strong>Delivery Days:</strong><br>{{ $order->deliveryRoute->delivery_days }} days</p>
                        @endif
                    </div>
                </div>
                @endif

                <!-- Actions -->
                <div class="card">
                    <div class="card-body p-3">
                        <button type="submit" class="btn btn-primary w-100 mb-2">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-secondary w-100">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
