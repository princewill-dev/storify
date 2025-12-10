@extends('home.layout')
@section('title', 'Payment')

@section('content')

<br><br><br><br>

<div class="page-content">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="fa fa-check-circle text-success" style="font-size: 64px;"></i>
                            <h2 class="mt-3">Order Placed Successfully!</h2>
                            <p class="text-muted">Order #{{ $order->order_number }}</p>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Customer Details</h5>
                                <p class="mb-1"><strong>Name:</strong> {{ $order->customer->full_name }}</p>
                                <p class="mb-1"><strong>Email:</strong> {{ $order->customer->email }}</p>
                                <p class="mb-1"><strong>Phone:</strong> {{ $order->customer->phone }}</p>
                            </div>
                            <div class="col-md-6">
                                <h5>Shipping Address</h5>
                                <p class="mb-0">{{ $order->customer->full_address }}</p>
                                @if($order->delivery_state && $order->delivery_area)
                                <p class="mb-0 mt-2"><strong>Delivery Location:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                                @endif
                                @if($order->deliveryRoute)
                                <p class="mb-0"><strong>Estimated Delivery:</strong> {{ $order->deliveryRoute->delivery_days }} days</p>
                                @endif
                            </div>
                        </div>

                        <h5 class="mb-3">Order Items</h5>
                        <div class="table-responsive mb-4">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Quantity</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($order->items as $item)
                                    <tr>
                                        <td>{{ $item->product_name }}</td>
                                        <td>₦{{ number_format($item->unit_price, 2) }}</td>
                                        <td>{{ $item->quantity }}</td>
                                        <td class="text-end">₦{{ number_format($item->subtotal, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>Subtotal:</strong></td>
                                        <td class="text-end">₦{{ number_format($order->subtotal, 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>Shipping:</strong></td>
                                        <td class="text-end">₦{{ number_format($order->shipping_fee, 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>Tax:</strong></td>
                                        <td class="text-end">₦{{ number_format($order->tax, 2) }}</td>
                                    </tr>
                                    <tr class="table-active">
                                        <td colspan="3" class="text-end"><strong>Total:</strong></td>
                                        <td class="text-end"><strong>₦{{ number_format($order->total, 2) }}</strong></td>
                                    </tr>
                                    @if($order->source === 'live_first')
                                    <tr class="table-success">
                                        <td colspan="3" class="text-end"><strong><i class="fa fa-rocket me-1"></i> Down Payment (10%):</strong></td>
                                        <td class="text-end"><strong class="text-success">₦{{ number_format($paymentAmount, 2) }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>Balance (90% over 6 months):</strong></td>
                                        <td class="text-end"><strong>₦{{ number_format($order->total - $paymentAmount, 2) }}</strong></td>
                                    </tr>
                                    @endif
                                </tfoot>
                            </table>
                        </div>

                        @if($order->source === 'live_first')
                        <div class="alert alert-success">
                            <h5 class="alert-heading"><i class="fa fa-rocket me-2"></i>Live First Program - Down Payment</h5>
                            <p class="mb-2">You're using the Live First program for this order. Pay only 10% now and the rest will be automatically deducted from your salary over 6 months.</p>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Down Payment (10%):</strong> <span class="text-success fs-5">₦{{ number_format($paymentAmount, 2) }}</span></p>
                                    <p class="mb-1"><strong>Balance (90%):</strong> ₦{{ number_format($order->total - $paymentAmount, 2) }}</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Monthly Payment:</strong> ₦{{ number_format(($order->total - $paymentAmount) / 6, 2) }}</p>
                                    <p class="mb-1"><strong>Payment Duration:</strong> 6 months</p>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- <div class="alert alert-info">
                            <h5 class="alert-heading">Payment Method: {{ $order->transactions->first()->paymentMethod->name }}</h5>
                            <p class="mb-0">{{ $order->transactions->first()->paymentMethod->description }}</p>
                            <hr>
                            <p class="mb-0"><strong>Transaction Reference:</strong> {{ $order->transactions->first()->reference }}</p>
                            <p class="mb-0"><strong>Amount to Pay:</strong> <span class="text-primary fs-5">₦{{ number_format($paymentAmount, 2) }}</span></p>
                            <p class="mb-0"><strong>Status:</strong> <span class="badge bg-warning">{{ $order->transactions->first()->status->label() }}</span></p>
                        </div> -->

                        @if($order->notes)
                        <div class="mb-3">
                            <h6>Order Notes:</h6>
                            <p class="text-muted">{{ $order->notes }}</p>
                        </div>
                        @endif

                        <div class="text-center mt-4">
                            <!-- @if($order->payment_status === \App\Enums\PaymentStatus::PAID)
                            <div class="alert alert-success">
                                <i style="padding: 5px" class="fa fa-check-circle"></i> <strong> Payment Confirmed!</strong><br>
                                Your order is being processed.
                            </div>
                            @endif -->
                            
                            <p class="text-muted">We will send you an email confirmation shortly.</p>
                            <a href="{{ route('home.store.products.index', ['store_slug' => $store->slug]) }}" class="btn btn-primary">Continue Shopping</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
