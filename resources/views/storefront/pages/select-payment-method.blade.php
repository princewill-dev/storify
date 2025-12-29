@extends('storefront.layout')
@section('title', 'Select Payment Method')

@section('content')
<br><br><br>
<section class="product__area pt-105 pb-110 grey-bg-2">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="product__details-sidebar">
                    <div class="product__proprietor white-bg mb-30">
                        <div class="product__proprietor-head mb-25">
                            <div class="product__prorietor-info d-flex align-items-center">
                                <div class="product__proprietor-thumb">
                                    <img src="{{ asset('storefront/assets/img/payment.png') }}" alt="Payment" onerror="this.src='{{ asset('storefront/assets/img/store-checkout.png') }}'">
                                </div>
                                <div class="product__proprietor-name">
                                    <h5>Choose Your Payment Method</h5>
                                    <p>Select how you'd like to pay for your order</p>
                                </div>
                            </div>
                        </div>
                        <div class="product__proprietor-body">
                            <div class="alert alert-info mb-4">
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

                            @if(session('error'))
                                <div class="alert alert-danger">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @if(session('success'))
                                <div class="alert alert-success">
                                    {{ session('success') }}
                                </div>
                            @endif

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

                                <h6 class="mb-3">Available Payment Methods</h6>
                                
                                @forelse($paymentMethods as $method)
                                <div class="card mb-3 payment-method-card" style="cursor: pointer;" data-payment-id="{{ $method->id }}">
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
                                                        <h6 class="mb-1">{{ $method->name }}</h6>
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
                                <button type="submit" class="m-btn m-btn-2 w-100 mt-4">
                                    Continue to Payment
                                </button>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.payment-method-card {
    border: 2px solid #e0e0e0;
    transition: all 0.3s ease;
}
.payment-method-card:hover {
    border-color: #4CAF50;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.payment-method-card.selected {
    border: 2px solid #4CAF50 !important;
    background-color: #f1f8f4;
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentCards = document.querySelectorAll('.payment-method-card');
    const paymentRadios = document.querySelectorAll('.payment-radio');
    
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
    
    updateSelection();
    
    paymentCards.forEach(card => {
        card.addEventListener('click', function() {
            const radio = this.querySelector('.payment-radio');
            radio.checked = true;
            updateSelection();
        });
    });
    
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', updateSelection);
    });
});
</script>

@endsection
