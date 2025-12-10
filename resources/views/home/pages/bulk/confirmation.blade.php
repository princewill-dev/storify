@extends('home.layout')
@section('title', 'Bulk Order Submitted')

@section('content')
<br>
<br>
<br>
<br>
<div class="page-content">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- Success Message -->
                <div class="card border-success mb-4">
                    <div class="card-header bg-success text-white text-center">
                        <h3 class="mb-0"><i class="fa fa-check-circle"></i> Order Submitted Successfully!</h3>
                    </div>
                    <div class="card-body text-center p-5">
                        <div class="mb-4">
                            <i class="fa fa-clipboard-check text-success" style="font-size: 64px;"></i>
                        </div>
                        <h4>Your Bulk Order is Being Reviewed</h4>
                        <p class="lead">Order Code: <strong class="text-primary">{{ $bulkOrder->bulk_code }}</strong></p>
                        <p class="text-muted">
                            Thank you for your bulk order! Our team will review your request and contact you shortly with pricing confirmation.
                        </p>
                        <a href="{{ route('bulk.order.review', ['store_slug' => $bulkOrder->store->slug, 'bulkCode' => $bulkOrder->bulk_code]) }}" class="btn btn-primary">Check bulk order status</a>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-box"></i> Order Details</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Order Code:</dt>
                            <dd class="col-sm-8"><strong>{{ $bulkOrder->bulk_code }}</strong></dd>

                            <dt class="col-sm-4">Status:</dt>
                            <dd class="col-sm-8">
                                <span class="badge bg-warning">Pending Review</span>
                            </dd>

                            <dt class="col-sm-4">Submitted:</dt>
                            <dd class="col-sm-8">{{ $bulkOrder->created_at->format('M d, Y \a\t h:i A') }}</dd>

                            <dt class="col-sm-4">Store:</dt>
                            <dd class="col-sm-8">{{ $bulkOrder->store->name ?? 'N/A' }}</dd>

                            <dt class="col-sm-4">Estimated Total:</dt>
                            <dd class="col-sm-8"><strong class="text-success">₦{{ number_format($bulkOrder->estimated_total, 2) }}</strong></dd>
                        </dl>

                        @if($bulkOrder->notes)
                            <hr>
                            <h6>Your Notes:</h6>
                            <p class="text-muted">{{ $bulkOrder->notes }}</p>
                        @endif
                    </div>
                </div>

                <!-- Items Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-list"></i> Items Ordered</h5>
                    </div>
                    <div class="card-body">
                        @if($bulkOrder->items->count() > 0)
                            <h6 class="mb-3">Products</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($bulkOrder->items as $item)
                                        <tr>
                                            <td>
                                                <strong>{{ $item->product_name }}</strong><br>
                                                <small class="text-muted">{{ $item->product_code }}</small>
                                            </td>
                                            <td class="text-center">{{ number_format($item->quantity) }}</td>
                                            <td class="text-end">₦{{ number_format($item->subtotal, 2) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if($bulkOrder->custom_items)
                            <h6 class="mb-3 mt-4">Custom Product Requests</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-end">Budgeted Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($bulkOrder->custom_items as $item)
                                        <tr>
                                            <td>{{ $item['name'] }}</td>
                                            <td class="text-center">{{ number_format($item['quantity']) }}</td>
                                            <td class="text-end">₦{{ number_format($item['budgeted_amount'], 2) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Delivery Address -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fa fa-map-marker-alt"></i> Delivery Address</h5>
                    </div>
                    <div class="card-body">
                        <address>
                            <strong>{{ $bulkOrder->deliveryAddress->recipient_name }}</strong><br>
                            {{ $bulkOrder->deliveryAddress->street_address }}<br>
                            @if($bulkOrder->deliveryAddress->apartment)
                                {{ $bulkOrder->deliveryAddress->apartment }}<br>
                            @endif
                            {{ $bulkOrder->deliveryAddress->deliveryRoute->area ?? '' }}, 
                            {{ $bulkOrder->deliveryAddress->deliveryRoute->state ?? '' }}<br>
                            Phone: {{ $bulkOrder->deliveryAddress->recipient_phone }}
                        </address>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="card border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fa fa-info-circle"></i> What Happens Next?</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li class="mb-2">
                                <strong>Review Process (24-48 hours)</strong><br>
                                <small class="text-muted">Our team will review your order and verify product availability and pricing.</small>
                            </li>
                            <li class="mb-2">
                                <strong>Pricing Confirmation</strong><br>
                                <small class="text-muted">You'll receive an email with final pricing and any adjustments.</small>
                            </li>
                            <li class="mb-2">
                                <strong>Payment Link</strong><br>
                                <small class="text-muted">Once approved, you'll receive a secure payment link via email.</small>
                            </li>
                            <li class="mb-0">
                                <strong>Order Processing</strong><br>
                                <small class="text-muted">After payment, your order will be processed and shipped.</small>
                            </li>
                        </ol>

                        <div class="alert alert-warning mt-3 mb-0">
                            <small>
                                <i class="fa fa-exclamation-triangle"></i>
                                <strong>Note:</strong> An email confirmation has been sent to {{ auth('customer')->user()->email }}
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="text-center mt-4">
                    <a href="{{ route('home.index') }}" class="btn btn-primary">
                        <i class="fa fa-home"></i> Return to Home
                    </a>
                    <a href="{{ $bulkOrder->store ? route('home.store.products.index', $bulkOrder->store->slug) : route('home.index') }}" class="btn btn-outline-secondary">
                        <i class="fa fa-shopping-bag"></i> Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
