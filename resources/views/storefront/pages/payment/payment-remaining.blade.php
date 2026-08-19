@extends('storefront.layout')
@section('title', 'Complete Payment')

@section('content')
<div class="page-content" style="background-color: #fafbfc; min-height: 80vh; display: flex; align-items: center; justify-content: center;">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-6">
                <div class="card border-0" style="box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 12px;">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <div style="width: 80px; height: 80px; background: #fffbeb; border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                                <i class="fa fa-hourglass-half" style="font-size: 36px; color: #f59e0b;"></i>
                            </div>
                            <h4 class="mt-3 mb-2" style="color: #1a1a1a; font-weight: 600;">Payment Partially Complete</h4>
                            <p class="text-muted" style="font-size: 15px;">
                                Thank you! A portion of order <strong>#{{ $order->order_number }}</strong> has been paid.
                            </p>
                        </div>

                        <div class="row text-center mb-4">
                            <div class="col-6">
                                <div class="p-3 rounded" style="background: #f8fafc;">
                                    <p class="text-muted mb-1" style="font-size: 12px;">Total</p>
                                    <strong style="font-size: 18px;">₦{{ number_format($order->total, 2) }}</strong>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded" style="background: #fffbeb;">
                                    <p class="text-muted mb-1" style="font-size: 12px;">Remaining Balance</p>
                                    <strong style="font-size: 18px; color: #d97706;">₦{{ number_format($remaining, 2) }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-3 col-lg-8 mx-auto">
                            @foreach($paymentMethods as $method)
                                @if($method->code === 'paystack')
                                <form method="POST" action="{{ route('checkout.payment-methods.select', ['store_subdomain' => $store->slug, 'order' => $order->order_number]) }}">
                                    @csrf
                                    <input type="hidden" name="payment_method" value="paystack">
                                    <input type="hidden" name="amount" value="{{ $remaining }}">
                                    <button type="submit" class="btn btn-primary btn-lg w-100" style="background: #1a1a1a; border: none; padding: 12px;">
                                        <i class="fa fa-credit-card me-2"></i> Pay Remaining via Card
                                    </button>
                                </form>
                                @endif
                                @if($method->code === 'bank_transfer')
                                <form method="POST" action="{{ route('checkout.payment-methods.select', ['store_subdomain' => $store->slug, 'order' => $order->order_number]) }}">
                                    @csrf
                                    <input type="hidden" name="payment_method" value="bank_transfer">
                                    <input type="hidden" name="amount" value="{{ $remaining }}">
                                    <button type="submit" class="btn btn-outline-dark btn-lg w-100" style="padding: 12px;">
                                        <i class="fa fa-university me-2"></i> Pay Remaining via Bank Transfer
                                    </button>
                                </form>
                                @endif
                            @endforeach

                            <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug, 'orderNumber' => $order->order_number]) }}" class="btn btn-light w-100" style="color: #64748b;">
                                View Order Status
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
