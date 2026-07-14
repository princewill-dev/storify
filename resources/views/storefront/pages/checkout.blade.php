@extends('storefront.layout')
@section('title', 'Checkout')

@section('content')
<section class="product__area pt-50 pb-110 grey-bg-2">
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
                                {{-- Login/Register prompt for guest users --}}
                                @unless(auth()->guard('customer')->check())
                                <div class="alert alert-light border mb-4 p-3 d-flex align-items-center justify-content-between" style="background:#f8f9fa;">
                                    <div>
                                        <p class="mb-1 fw-semibold small">Have an account?</p>
                                        <p class="mb-0 text-muted" style="font-size:12px;">Login or create an account to save your info for faster checkout next time.</p>
                                    </div>
                                    <div class="d-flex gap-2 flex-shrink-0 ms-3">
                                        <a href="{{ route('account.login', ['checkout_code' => $cart->checkout_token, 'store' => $store->slug]) }}" class="btn btn-sm btn-outline-dark">Login</a>
                                        <a href="{{ route('account.register', ['checkout_code' => $cart->checkout_token, 'store' => $store->slug]) }}" class="btn btn-sm btn-dark">Register</a>
                                    </div>
                                </div>
                                @else
                                <div class="alert alert-light border mb-4 p-3 d-flex align-items-center gap-3" style="background:#f8f9fa;">
                                    <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;">
                                        <span class="fw-bold small">{{ strtoupper(substr($customer->first_name ?? 'U', 0, 1)) }}</span>
                                    </div>
                                    <div>
                                        <p class="mb-0 fw-semibold small">Welcome back, {{ $customer->first_name }}</p>
                                        <p class="mb-0 text-muted" style="font-size:12px;">Your info is pre-filled from your account. <a href="{{ route('account.dashboard') }}">Manage addresses →</a></p>
                                    </div>
                                </div>
                                @endunless

                                <form action="{{ route('checkout.process', ['store_subdomain' => $store->slug]) }}" method="POST" id="checkoutForm">
                                    @csrf
                                    <input type="hidden" name="checkout_token" value="{{ $cart->checkout_token ?? '' }}">
                                    <input type="hidden" name="is_guest" value="{{ auth()->guard('customer')->check() ? 'false' : 'true' }}">
                                
                                <h6 class="mb-3">Personal Information</h6>
                                @if(isset($preselectedRoute))
                                    <input type="hidden" name="delivery_route_id" value="{{ $preselectedRoute->id }}">
                                @endif
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

                                {{-- Saved Addresses (logged-in customers only) --}}
                                @if(auth()->guard('customer')->check() && isset($savedAddresses) && $savedAddresses->isNotEmpty())
                                <div class="mb-3">
                                    <label class="form-label">Use Saved Address</label>
                                    <select class="form-select" id="savedAddressSelect" onchange="fillSavedAddress(this)">
                                        <option value="">— Enter new address —</option>
                                        @foreach($savedAddresses as $addr)
                                        <option value="{{ $addr->id }}"
                                            data-name="{{ $addr->recipient_name }}"
                                            data-phone="{{ $addr->recipient_phone }}"
                                            data-street="{{ $addr->street_address }}"
                                            data-apartment="{{ $addr->apartment }}"
                                            data-city="{{ $addr->city }}"
                                            data-state="{{ $addr->state }}"
                                            data-country="{{ $addr->country }}"
                                            data-zip="{{ $addr->zip_code }}"
                                            data-route="{{ $addr->delivery_route_id }}"
                                            {{ $addr->is_default ? 'selected' : '' }}>
                                            {{ $addr->label }} — {{ $addr->street_address }}, {{ $addr->city }}
                                            @if($addr->is_default)(Default)@endif
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                                @endif

                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Country *</label>
                                        <input type="text" name="country" class="form-control" value="{{ old('country', 'Nigeria') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State *</label>
                                        <input type="text" name="state" class="form-control" value="{{ old('state', $preselectedRoute->state ?? '') }}" placeholder="e.g. Lagos" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City *</label>
                                        <input type="text" name="city" class="form-control" value="{{ old('city', $preselectedRoute->area ?? '') }}" placeholder="e.g. Ikeja" required>
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
                            @if(isset($shippingFee) && $shippingFee > 0)
                            <div class="d-flex justify-content-between mb-2">
                                <span>Shipping</span>
                                <span>₦{{ number_format($shippingFee / 100, 2) }}</span>
                            </div>
                            @endif
                            <hr>
                            <div class="d-flex justify-content-between mb-2 font-weight-bold" style="font-size: 1.2rem;">
                                <span>Total</span>
                                <span id="grandTotalDisplay">₦{{ number_format(($cart->total + ($shippingFee ?? 0)) / 100, 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@push('scripts')
<script>
function fillSavedAddress(select) {
    if (!select.value) return;
    var opt = select.selectedOptions[0];
    var fields = {
        'input[name="first_name"]': opt.dataset.name ? opt.dataset.name.split(' ')[0] : '',
        'input[name="last_name"]': opt.dataset.name ? opt.dataset.name.split(' ').slice(1).join(' ') : '',
        'input[name="phone"]': opt.dataset.phone || '',
        'textarea[name="street_address"]': opt.dataset.street || '',
        'input[name="apartment"]': opt.dataset.apartment || '',
        'input[name="city"]': opt.dataset.city || '',
        'input[name="state"]': opt.dataset.state || '',
        'input[name="country"]': opt.dataset.country || 'Nigeria',
        'input[name="zip_code"]': opt.dataset.zip || '',
    };
    for (var selector in fields) {
        var el = document.querySelector(selector);
        if (el) el.value = fields[selector];
    }
    if (opt.dataset.route) {
        var routeEl = document.querySelector('input[name="delivery_route_id"], select[name="delivery_route_id"]');
        if (routeEl) routeEl.value = opt.dataset.route;
    }
}
// Auto-fill on page load if default is selected
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('savedAddressSelect');
    if (sel && sel.value) fillSavedAddress(sel);
});
</script>
@endpush
@endsection