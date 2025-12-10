@extends('home.layout')
@section('title', 'Select Payment Method')

@section('content')

<br><br><br><br>

<div class="page-content">
    <div class="dz-bnr-inr" style="background-image:url({{ asset('home/images/background/bg-shape.jpg') }});">
        <div class="container">
            <div class="dz-bnr-inr-entry">
                <h1>Select Payment Method</h1>
                <nav aria-label="breadcrumb" class="breadcrumb-row">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('home.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('checkout.index', ['store_slug' => $store->slug]) }}">Checkout</a></li>
                        <li class="breadcrumb-item">Payment Method</li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <section class="content-inner-1">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <i class="fa fa-credit-card text-success" style="font-size: 64px;"></i>
                                <h2 class="mt-3">Choose Your Payment Method</h2>
                                <p class="text-muted">Select how you'd like to pay for your order</p>
                            </div>

                            <div class="alert alert-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Order #{{ $order->order_number }}</strong><br>
                                        <small>{{ $order->items->count() }} items</small>
                                        @if($order->source === 'live_first')
                                            <br><small class="text-success"><i class="fa fa-rocket me-1"></i> Live First - Down Payment (10%)</small>
                                        @endif
                                    </div>
                                    <div class="text-end">
                                        <h4 class="mb-0">₦{{ number_format($paymentAmount, 2) }}</h4>
                                        @if($order->source === 'live_first')
                                            <small class="text-muted">Full order: ₦{{ number_format($order->total, 2) }}</small>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if($errors->any())
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('checkout.payment-methods.select', ['store_slug' => $store->slug, 'order' => $order->order_number]) }}">
                                @csrf

                                <h5 class="mb-3">Available Payment Methods</h5>
                                
                                @forelse($paymentMethods as $method)
                                <div class="card mb-3 payment-method-card" style="cursor: pointer; transition: all 0.3s ease;" data-payment-id="{{ $method->id }}">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input 
                                                class="form-check-input payment-radio" 
                                                type="radio" 
                                                name="payment_method_id" 
                                                id="payment_{{ $method->id }}" 
                                                value="{{ $method->id }}" 
                                                {{ $loop->first ? 'checked' : '' }}
                                                required
                                            >
                                            <label class="form-check-label w-100" for="payment_{{ $method->id }}" style="cursor: pointer;">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h5 class="mb-1">{{ $method->name }}</h5>
                                                        @if($method->description)
                                                        <p class="text-muted mb-0 small">{{ $method->description }}</p>
                                                        @endif
                                                    </div>
                                                    @if($method->code === 'bank_transfer')
                                                    <i class="fa fa-university fa-2x text-primary"></i>
                                                    @elseif($method->code === 'cod')
                                                    <i class="fa fa-money-bill-wave fa-2x text-success"></i>
                                                    @elseif($method->code === 'paystack')
                                                    <i class="fa fa-credit-card fa-2x text-info"></i>
                                                    @else
                                                    <i class="fa fa-wallet fa-2x text-secondary"></i>
                                                    @endif
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                @empty
                                <div class="alert alert-warning">
                                    No payment methods available. Please contact support.
                                </div>
                                @endforelse

                                @if($paymentMethods->isNotEmpty())
                                <button type="submit" class="btn btn-secondary w-100 btn-lg mt-4">
                                    Continue to Payment
                                </button>
                                @endif
                            </form>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <!-- <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="mb-3">Order Summary</h5>
                            @foreach($order->items as $item)
                            <div class="d-flex justify-content-between mb-2">
                                <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                                <span>₦{{ number_format($item->subtotal, 2) }}</span>
                            </div>
                            @endforeach
                            <hr>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal</span>
                                <span>₦{{ number_format($order->subtotal, 2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Shipping</span>
                                <span>₦{{ number_format($order->shipping_fee, 2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tax</span>
                                <span>₦{{ number_format($order->tax, 2) }}</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <strong>Total</strong>
                                <strong class="text-primary">₦{{ number_format($order->total, 2) }}</strong>
                            </div>
                        </div>
                    </div> -->
                </div>
            </div>
        </div>
    </section>
</div>

<style>
.payment-method-card {
    border: 3px solid #e0e0e0;
}
.payment-method-card:hover {
    border-color: #4CAF50;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.payment-method-card.selected {
    border: 3px solid #4CAF50 !important;
    background-color: #f1f8f4;
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
}
.payment-method-card .form-check-input:checked ~ .form-check-label {
    color: #4CAF50;
    font-weight: 600;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentCards = document.querySelectorAll('.payment-method-card');
    const paymentRadios = document.querySelectorAll('.payment-radio');
    
    // Function to update selected state
    function updateSelection() {
        paymentCards.forEach(card => {
            const radio = card.querySelector('.payment-radio');
            if (radio.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
    }
    
    // Initial selection
    updateSelection();
    
    // Add click handlers to cards
    paymentCards.forEach(card => {
        card.addEventListener('click', function() {
            const radio = this.querySelector('.payment-radio');
            radio.checked = true;
            updateSelection();
        });
    });
    
    // Add change handlers to radios
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', updateSelection);
    });
});
</script>

@endsection
