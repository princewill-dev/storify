@extends('home.layout')

@section('title', 'Payment Successful')

@section('content')
<br>
<br>
<br>
<div class="page-content">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-6">
                <div class="card border-success">
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <i class="fa fa-check-circle text-success" style="font-size: 80px;"></i>
                        </div>
                        
                        <h2 class="text-success mb-3">Payment Successful!</h2>
                        
                        <p class="lead mb-4">
                            Your payment has been verified and your order is being processed.
                        </p>

                        @if($order ?? null)
                            <div class="order-details bg-light p-4 rounded mb-4">
                                <div class="row">
                                    <div class="col-6 text-start">
                                        <small class="text-muted d-block">Order Code</small>
                                        <strong>{{ $order->order_code }}</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        <small class="text-muted d-block">Amount Paid</small>
                                        <strong class="text-success">₦{{ number_format($order->total, 2) }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <i class="fa fa-info-circle me-2"></i>
                                A confirmation email has been sent to your email address.
                            </div>

                            <div class="d-grid gap-2">
                                <a href="{{ route('account.order.show', ['orderNumber' => $order->order_number]) }}" class="btn btn-primary btn-lg">
                                    <i class="fa fa-eye me-2"></i>View Order Details
                                </a>
                                <a href="{{ route('home.index') }}" class="btn btn-outline-secondary">
                                    <i class="fa fa-home me-2"></i>Continue Shopping
                                </a>
                            </div>
                        @else
                            <a href="{{ route('home.index') }}" class="btn btn-primary btn-lg">
                                <i class="fa fa-home me-2"></i>Go to Home
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
