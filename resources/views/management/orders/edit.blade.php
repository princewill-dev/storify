@extends('management.layout')
@section('subtitle', 'Edit Order - #' . $order->order_number)

@section('content')
<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="h3 mb-0">Edit Order #{{ $order->order_number }}</h1>
                <a href="{{ route('management.orders.show') }}" class="btn btn-secondary">
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

    <form action="{{ route('management.orders.update') }}" method="POST">
        @csrf
        @method('PUT')

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Order Items (Read-only) -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Order Items</h5>
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
                                        <td>{{ $item->product_name }}</td>
                                        <td>{{ $item->product_code }}</td>
                                        <td>₦{{ number_format($item->unit_price, 2) }}</td>
                                        <td>{{ $item->quantity }}</td>
                                        <td>₦{{ number_format($item->subtotal, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Pricing</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subtotal (Read-only)</label>
                                <input type="text" class="form-control" value="₦{{ number_format($order->subtotal, 2) }}" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Shipping Fee</label>
                                <input type="number" name="shipping_fee" class="form-control" step="0.01" value="{{ old('shipping_fee', $order->shipping_fee) }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tax</label>
                                <input type="number" name="tax" class="form-control" step="0.01" value="{{ old('tax', $order->tax) }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total (Calculated)</label>
                                <input type="text" class="form-control" value="₦{{ number_format($order->total, 2) }}" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Notes -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Order Notes</h5>
                    </div>
                    <div class="card-body">
                        <textarea name="notes" class="form-control" rows="5" placeholder="Add any notes about this order...">{{ old('notes', $order->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Order Status</label>
                            <select name="status" class="form-control">
                                <option value="pending" {{ old('status', $order->status) === 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="accepted" {{ old('status', $order->status) === 'accepted' ? 'selected' : '' }}>Accepted</option>
                                <option value="processing" {{ old('status', $order->status) === 'processing' ? 'selected' : '' }}>Processing</option>
                                <option value="dispatched" {{ old('status', $order->status) === 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                                <option value="delivered" {{ old('status', $order->status) === 'delivered' ? 'selected' : '' }}>Delivered</option>
                                <option value="completed" {{ old('status', $order->status) === 'completed' ? 'selected' : '' }}>Completed</option>
                                <option value="cancelled" {{ old('status', $order->status) === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                <option value="returned" {{ old('status', $order->status) === 'returned' ? 'selected' : '' }}>Returned</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" class="form-control">
                                <option value="unpaid" {{ old('payment_status', $order->payment_status) === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                <option value="paid" {{ old('payment_status', $order->payment_status) === 'paid' ? 'selected' : '' }}>Paid</option>
                                <option value="refunded" {{ old('payment_status', $order->payment_status) === 'refunded' ? 'selected' : '' }}>Refunded</option>
                                <option value="failed" {{ old('payment_status', $order->payment_status) === 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Customer Info (Read-only) -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Customer</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong><br>{{ $order->customer->full_name }}</p>
                        <p><strong>Email:</strong><br>{{ $order->customer->email }}</p>
                        <p><strong>Phone:</strong><br>{{ $order->customer->phone }}</p>
                    </div>
                </div>

                <!-- Delivery Info (Read-only) -->
                @if($order->delivery_state || $order->deliveryRoute)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Delivery</h5>
                    </div>
                    <div class="card-body">
                        @if($order->delivery_state && $order->delivery_area)
                        <p><strong>Location:</strong><br>{{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                        @endif
                        @if($order->deliveryRoute)
                        <p><strong>Route:</strong><br>{{ $order->deliveryRoute->name }}</p>
                        <p><strong>Delivery Days:</strong><br>{{ $order->deliveryRoute->delivery_days }} days</p>
                        @endif
                    </div>
                </div>
                @endif

                <!-- Actions -->
                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary w-100 mb-2">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('management.orders.show') }}" class="btn btn-secondary w-100">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
