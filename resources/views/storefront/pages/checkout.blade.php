@extends('storefront.layout')
@section('title', 'Checkout')

@section('content')
<section class="product__area pt-105 pb-110 grey-bg-2">
    <div class="container">
        <div class="row">
            <div class="col-lg-8">
                <div class="product__details-sidebar">
                    <div class="product__proprietor white-bg mb-30">
                        <div class="product__proprietor-head mb-25">
                            <div class="product__prorietor-info d-flex align-items-center">
                                <div class="product__proprietor-thumb">
                                    <img src="{{ asset('storefront/assets/img/store-checkout.png') }}" alt="Store">
                                </div>
                                <div class="product__proprietor-name">
                                    <h5>Checkout Details</h5>
                                    <p>Please provide your info to complete your order</p>
                                </div>
                            </div>
                        </div>
                        <div class="product__proprietor-body">
                            <form action="{{ route('checkout.process', ['store_slug' => $store->slug]) }}" method="POST" id="checkoutForm">
                                @csrf
                                
                                <h6 class="mb-3">Personal Information</h6>
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">First Name *</label>
                                        <input type="text" name="first_name" class="form-control" value="{{ old('first_name', $customer->first_name ?? '') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Last Name *</label>
                                        <input type="text" name="last_name" class="form-control" value="{{ old('last_name', $customer->last_name ?? '') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email Address *</label>
                                        <input type="email" name="email" class="form-control" value="{{ old('email', $customer->email ?? '') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone Number *</label>
                                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $customer->phone ?? '') }}" required>
                                    </div>
                                </div>

                                <hr>

                                <h6 class="mb-3 mt-4">Delivery Information</h6>
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Country *</label>
                                        <input type="text" name="country" class="form-control" value="{{ old('country', 'Nigeria') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State *</label>
                                        <input type="text" name="state" class="form-control" value="{{ old('state') }}" placeholder="e.g. Lagos" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City *</label>
                                        <input type="text" name="city" class="form-control" value="{{ old('city') }}" placeholder="e.g. Ikeja" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Landmark (Optional)</label>
                                        <input type="text" name="landmark" class="form-control" value="{{ old('landmark') }}" placeholder="e.g. Near ABC School">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Street Address *</label>
                                        <textarea name="street_address" class="form-control" rows="2" placeholder="e.g. 123 Main St" required>{{ old('street_address') }}</textarea>
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">House/Apartment Number (Optional)</label>
                                        <input type="text" name="apartment" class="form-control" value="{{ old('apartment') }}" placeholder="e.g. Suite 404">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Order Notes (Optional)</label>
                                        <textarea name="notes" class="form-control" rows="2" placeholder="Any special instructions for delivery">{{ old('notes') }}</textarea>
                                    </div>
                                </div>

                                <button type="submit" class="m-btn m-btn-2 w-100 mb-20">Proceed to Payment </button>
                                
                                <div class="text-center">
                                    <a href="{{ route('home.store.cart', ['store_subdomain' => $store->slug]) }}">Return to Cart</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="product__details-sidebar ml-30" style="padding: 20px;">
                    <div class="product__order white-bg" style="padding: 20px;">
                        <div class="product__order-head mb-30">
                            <h3>Order Summary</h3>
                        </div>
                        <div class="product__order-info">
                            <ul class="list-unstyled">
                                @foreach($cartSummaryItems as $item)
                                <li class="d-flex justify-content-between mb-2">
                                    <span>{{ $item['name'] }} x {{ $item['qty'] }}</span>
                                    <span>₦{{ number_format($item['total'], 2) }}</span>
                                </li>
                                @endforeach
                            </ul>
                            <hr>
                            <div class="d-flex justify-content-between mb-2 mt-3">
                                <span>Subtotal</span>
                                <span>₦{{ number_format($cart->subtotal / 100, 2) }}</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between mb-2 font-weight-bold" style="font-size: 1.2rem;">
                                <span>Total</span>
                                <span id="grandTotalDisplay">₦{{ number_format($cart->total / 100, 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection