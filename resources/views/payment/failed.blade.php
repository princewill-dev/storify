@extends('home.layout')

@section('title', 'Payment Failed')

@section('content')
<br>
<br>
<br>
<div class="page-content">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-6">
                <div class="card border-danger">
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <i class="fa fa-times-circle text-danger" style="font-size: 80px;"></i>
                        </div>
                        
                        <h2 class="text-danger mb-3">Payment Failed</h2>
                        
                        <p class="lead mb-4">
                            Unfortunately, we couldn't process your payment. Please try again.
                        </p>

                        @if($order ?? null)
                            <div class="order-details bg-light p-4 rounded mb-4">
                                <div class="row">
                                    <div class="col-6 text-start">
                                        <small class="text-muted d-block">Order Code</small>
                                        <strong>{{ $order->order_code }}</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        <small class="text-muted d-block">Amount</small>
                                        <strong>₦{{ number_format($order->total, 2) }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-triangle me-2"></i>
                                <strong>Common reasons for payment failure:</strong>
                                <ul class="text-start mt-2 mb-0">
                                    <li>Insufficient funds</li>
                                    <li>Incorrect card details</li>
                                    <li>Bank declined transaction</li>
                                    <li>Network issues</li>
                                </ul>
                            </div>

                            <div class="d-grid gap-2">
                                <a href="{{ route('account.order.show', ['orderNumber' => $order->order_number]) }}" class="btn btn-outline-secondary">
                                    <i class="fa fa-eye me-2"></i>View Order Details
                                </a>
                                <a href="{{ route('home.index') }}" class="btn btn-primary btn-lg">
                                    <i class="fa fa-home me-2"></i>Go to Home
                                </a>
                            </div>
                        @else
                            <a href="{{ route('home.index') }}" class="btn btn-primary btn-lg">
                                <i class="fa fa-home me-2"></i>Go to Home
                            </a>
                        @endif

                        <div class="mt-4">
                            <small class="text-muted">
                                Need help? <a href="{{ route('home.support.index', ['store_slug' => config('app.main_store_slug', 'zimozi_swift')]) }}">Contact Support</a>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
