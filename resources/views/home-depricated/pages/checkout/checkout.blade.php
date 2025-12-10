@extends('home.layout')
@section('title', 'Checkout')

@section('content')

<br><br><br><br>

<div class="page-content">
    <div class="dz-bnr-inr" style="background-image:url({{ asset('home/images/background/bg-shape.jpg') }});">
        <div class="container">
            <div class="dz-bnr-inr-entry">
                <h1>Checkout</h1>
                <nav aria-label="breadcrumb" class="breadcrumb-row">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('home.index') }}">Home</a></li>
                        <li class="breadcrumb-item">Checkout</li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <div class="content-inner-1">
        <div class="container">
            <style>
                .saved-address-option {
                    border: 1px solid #d1d5db;
                    transition: border-color 0.2s ease, box-shadow 0.2s ease;
                    border-radius: 14px;
                }

                .saved-address-option.selected {
                    border-color: #111827;
                    box-shadow: 0 0 0 0.18rem rgba(17, 24, 39, 0.18);
                }

                .checkout-address-modal .modal-dialog {
                    max-width: 860px;
                }

                .checkout-address-modal .modal-content {
                    border-radius: 18px;
                    border: 1px solid #d8dce3;
                    background: #f6f7f9;
                    color: #111827;
                    box-shadow: 0 28px 50px rgba(15, 23, 42, 0.16);
                }

                .checkout-address-modal .modal-header {
                    border: 0;
                    padding: 2rem 2rem 0;
                    background: transparent;
                }

                .checkout-address-modal .modal-title {
                    font-weight: 600;
                    font-size: 1.5rem;
                    letter-spacing: -0.01em;
                }

                .checkout-address-modal .modal-subtitle {
                    font-size: 0.9rem;
                    color: #6b7280;
                    margin: 0.45rem 0 0;
                }

                .checkout-address-modal .modal-body {
                    padding: 0 2rem 2rem;
                }

                .checkout-modal-body {
                    display: flex;
                    flex-direction: column;
                    gap: 1.75rem;
                }

                .section-block {
                    background: #ffffff;
                    border: 1px solid #e3e6ec;
                    border-radius: 16px;
                    padding: 1.75rem;
                    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
                }

                .section-header {
                    margin-bottom: 1rem;
                }

                .section-header h6 {
                    font-size: 0.82rem;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    color: #6b7280;
                    margin-bottom: 0.35rem;
                }

                .section-header .section-helper {
                    font-size: 0.9rem;
                    color: #4b5563;
                    margin: 0;
                }

                .form-grid {
                    display: grid;
                    gap: 1rem 1.25rem;
                }

                .form-grid.two-column {
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                }

                .form-grid.one-column {
                    grid-template-columns: 1fr;
                    gap: 1.25rem;
                }

                .form-grid .full-span {
                    grid-column: 1 / -1;
                }

                .checkout-address-modal .form-control,
                .checkout-address-modal .form-select,
                .checkout-address-modal textarea {
                    border-radius: 12px;
                    border: 1px solid #cfd4dc;
                    background: #f9fafb;
                    color: #111827;
                    padding: 0.75rem 0.9rem;
                    font-size: 0.95rem;
                }

                .checkout-address-modal .form-control:focus,
                .checkout-address-modal .form-select:focus,
                .checkout-address-modal textarea:focus {
                    border-color: #9ca3af;
                    background: #f5f6f8;
                    box-shadow: none;
                    color: #111827;
                }

                .checkout-address-modal label.label-title {
                    font-weight: 500;
                    font-size: 0.9rem;
                    color: #1f2933;
                    margin-bottom: 0.45rem;
                }

                .checkout-modal-body .form-group {
                    margin-bottom: 0;
                }

                .delivery-info-card {
                    display: none;
                    background: #f3f4f6;
                    border: 1px dashed #c4cad3;
                    border-radius: 14px;
                    padding: 1.25rem 1.5rem;
                    gap: 0.85rem;
                }

                .delivery-info-card .metric {
                    display: flex;
                    justify-content: space-between;
                    color: #374151;
                    font-weight: 500;
                    font-size: 0.95rem;
                }

                .meta-note {
                    font-size: 0.82rem;
                    color: #6b7280;
                    margin-top: 0.3rem;
                }

                .make-default-checkbox {
                    width: 1.15rem;
                    height: 1.15rem;
                    opacity: 1;
                    appearance: auto;
                    margin: 0;
                }

                .checkout-address-modal .form-check-label {
                    color: #4b5563;
                    font-size: 0.9rem;
                    margin-left: 0.65rem;
                }

                .checkout-address-modal .modal-footer {
                    border: 0;
                    padding: 1.75rem 2rem 2rem;
                    background: transparent;
                }

                .checkout-address-modal .btn-primary {
                    background: #111827;
                    border-color: #111827;
                    padding: 0.75rem 1.75rem;
                    border-radius: 10px;
                    font-weight: 600;
                    letter-spacing: 0.02em;
                }

                .checkout-address-modal .btn-primary:hover {
                    background: #1f2937;
                    border-color: #1f2937;
                }

                .checkout-address-modal .btn-outline-secondary {
                    border-radius: 10px;
                    border-color: #d1d5db;
                    color: #374151;
                    padding: 0.75rem 1.5rem;
                    background: #ffffff;
                }

                .checkout-address-modal .btn-outline-secondary:hover {
                    background: #f3f4f6;
                }

                @media (max-width: 575px) {
                    .checkout-address-modal .modal-dialog {
                        margin: 1.5rem;
                    }

                    .checkout-address-modal .modal-header,
                    .checkout-address-modal .modal-body,
                    .checkout-address-modal .modal-footer {
                        padding: 1.5rem;
                    }

                    .section-block {
                        padding: 1.25rem;
                    }
                }
            </style>
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('checkout.process', ['store_slug' => $store->slug]) }}" id="checkoutForm">
                @csrf
                <div class="row shop-checkout">
                    <div class="col-xl-8">
                        <h5 class="title m-b15">
                            Delivery Address 
                            <button type="button" class="btn btn-outline-primary btn-sm float-end" id="addNewAddressBtn" data-bs-toggle="modal" data-bs-target="#newAddressModal">
                                <i class="fas fa-plus me-1"></i>Add New Address
                            </button>
                        </h5>
                        <!-- <p class="text-muted mb-4">Logged in as: <strong>{{ auth()->guard('customer')->user()->email }}</strong></p> -->

                        <div class="card mb-4">
                            <div class="card-body">

                                @if($customerAddresses->count())
                                    <div class="row g-3">
                                        @foreach($customerAddresses as $address)
                                            @php
                                                $route = $address->deliveryRoute;
                                                $routeState = optional($route)->state ?? $address->delivery_state ?? $address->state;
                                                $routeArea = optional($route)->area ?? $address->delivery_area ?? $address->city;
                                                $routeFee = optional($route)->fee ?? $address->delivery_fee;
                                                $routeDays = optional($route)->delivery_days ?? $address->delivery_days;
                                            @endphp
                                            <div class="col-md-12">
                                                <label class="card h-100 saved-address-option{{ ($oldSelectedAddress ?? '') === (string) $address->id ? ' selected' : '' }}" data-address-id="{{ $address->id }}">
                                                    <div class="card-body d-flex">
                                                        <div class="form-check me-3 mt-1">
                                                            <input class="form-check-input" type="radio" name="selected_address_id" value="{{ $address->id }}" {{ ($oldSelectedAddress ?? '') === (string) $address->id ? 'checked' : ((!$oldSelectedAddress && $loop->first) ? 'checked' : '') }}>
                                                        </div>
                                                        <div>
                                                            <div class="d-flex align-items-center">
                                                                <span class="fw-bold">{{ $address->label ?? 'Address '.($loop->iteration) }}</span>
                                                                @if($address->is_default)
                                                                    <span class="badge bg-primary ms-2">Default</span>
                                                                @endif
                                                            </div>
                                                            <div class="text-muted small">{{ $address->recipient_name }} • {{ $address->recipient_phone }}</div>
                                                            <div class="mt-2">
                                                                {{ $address->street_address }}{{ $address->apartment ? ', '.$address->apartment : '' }}
                                                                @if($routeArea)
                                                                    , {{ $routeArea }}
                                                                @endif
                                                                @if($routeState)
                                                                    , {{ $routeState }}
                                                                @endif
                                                            </div>
                                                            @if($routeState || $routeArea)
                                                                <div class="text-muted small mt-2">
                                                                    <i class="fas fa-map-marker-alt me-1"></i>{{ $routeState ?? '—' }} — {{ $routeArea ?? 'Area not set' }}
                                                                </div>
                                                            @endif
                                                            @if($routeFee)
                                                                <div class="text-muted small mt-1">
                                                                    Delivery Fee: ₦{{ number_format($routeFee / 100, 2) }}
                                                                    @if($routeDays)
                                                                        • ETA: {{ $routeDays }} day(s)
                                                                    @endif
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="alert alert-info mb-0">
                                        You have no saved delivery addresses yet. Click "Add New Address" to create one.
                                    </div>
                                @endif

                                <input class="form-check-input d-none" type="radio" name="selected_address_id" id="addressNewOption" value="new" {{ $oldSelectedAddress === 'new' || !$customerAddresses->count() ? 'checked' : '' }}>
                            </div>
                        </div>

                        @if(!$customerAddresses->count())
                            <input type="hidden" name="selected_address_id" value="new">
                        @endif

                        <div class="alert alert-light border" id="addressDetailsPlaceholder" style="display: none;">
                            Select an existing address or add a new one to provide delivery details.
                        </div>

                        <div class="modal fade checkout-address-modal" id="newAddressModal" tabindex="-1" aria-labelledby="newAddressModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title" id="newAddressModalLabel">New Delivery Address</h5>
                                            <p class="modal-subtitle">Add a delivery location and recipient details.</p>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="checkout-modal-body">
                                            <section class="section-block">
                                                <div class="section-header">
                                                    <h6>Delivery location</h6>
                                                    <p class="section-helper">Choose your state and delivery area to preview fees and delivery time.</p>
                                                </div>
                                                <div class="form-grid two-column">
                                                    <div class="form-group">
                                                        <label class="label-title">State *</label>
                                                        <select id="deliveryStateSelect" name="delivery_state" class="form-select @error('delivery_route_id') is-invalid @enderror" required>
                                                            <option value="">Select State</option>
                                                            @foreach(($states ?? []) as $st)
                                                                <option value="{{ $st }}">{{ $st }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="label-title">Area *</label>
                                                        <select id="deliveryAreaSelect" name="delivery_route_id" class="form-select @error('delivery_route_id') is-invalid @enderror" required disabled>
                                                            <option value="">Select Area</option>
                                                        </select>
                                                        @error('delivery_route_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                                    </div>
                                                </div>
                                                <div id="deliveryInfo" class="delivery-info-card" style="display:none;">
                                                    <div class="metric">
                                                        <span>Delivery Fee</span>
                                                        <strong id="deliveryFeeDisplay">—</strong>
                                                    </div>
                                                    <div class="metric">
                                                        <span>Delivery Time</span>
                                                        <strong id="deliveryDaysDisplay">—</strong>
                                                    </div>
                                                </div>
                                            </section>

                                            <section class="section-block">
                                                <div class="section-header">
                                                    <h6>Recipient & address</h6>
                                                    <p class="section-helper">Tell us who should receive this delivery.</p>
                                                </div>
                                                <div class="form-grid two-column">
                                                    <div class="form-group full-span">
                                                        <label class="label-title">Address Label (optional)</label>
                                                        <input type="text" name="label" value="{{ old('label', $prefillAddress['label'] ?? '') }}" class="form-control @error('label') is-invalid @enderror" placeholder="e.g., Home, Office" data-address-field="1">
                                                        <small class="meta-note">Give this address a friendly name for quick recognition.</small>
                                                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="label-title">Recipient Name *</label>
                                                        <input type="text" name="recipient_name" value="{{ old('recipient_name', $prefillAddress['recipient_name'] ?? ($customer->first_name . ' ' . $customer->last_name)) }}" required class="form-control @error('recipient_name') is-invalid @enderror" data-address-field="1">
                                                        @error('recipient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="label-title">Recipient Phone *</label>
                                                        <input type="tel" name="recipient_phone" value="{{ old('recipient_phone', $prefillAddress['recipient_phone'] ?? $customer->phone) }}" required class="form-control @error('recipient_phone') is-invalid @enderror" data-address-field="1">
                                                        @error('recipient_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group full-span">
                                                        <label class="label-title">Company Name (optional)</label>
                                                        <input type="text" name="company_name" value="{{ old('company_name', $prefillAddress['company_name'] ?? '') }}" class="form-control @error('company_name') is-invalid @enderror" data-address-field="1">
                                                        @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="label-title">ZIP Code (optional)</label>
                                                        <input type="text" name="zip_code" value="{{ old('zip_code', $prefillAddress['zip_code'] ?? '') }}" class="form-control @error('zip_code') is-invalid @enderror" data-address-field="1">
                                                        @error('zip_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group full-span">
                                                        <label class="label-title">Street Address *</label>
                                                        <input type="text" name="street_address" value="{{ old('street_address', $prefillAddress['street_address'] ?? '') }}" required class="form-control @error('street_address') is-invalid @enderror" placeholder="House number and street name" data-address-field="1">
                                                        @error('street_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="label-title">Apartment / Suite (optional)</label>
                                                        <input type="text" name="apartment" value="{{ old('apartment', $prefillAddress['apartment'] ?? '') }}" class="form-control @error('apartment') is-invalid @enderror" placeholder="Apartment, suite, unit, etc." data-address-field="1">
                                                        @error('apartment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group full-span">
                                                        <label class="label-title">Google Maps Link (optional)</label>
                                                        <input type="url" name="map_link" value="{{ old('map_link', $prefillAddress['map_link'] ?? '') }}" class="form-control @error('map_link') is-invalid @enderror" placeholder="https://maps.google.com/..." data-address-field="1">
                                                        <small class="meta-note">Sharing a map link helps couriers find you faster.</small>
                                                        @error('map_link')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                </div>
                                            </section>

                                            <section class="section-block">
                                                <div class="section-header">
                                                    <h6>Preferences</h6>
                                                    <p class="section-helper">Optional details to help our couriers.</p>
                                                </div>
                                                <div class="form-grid one-column">
                                                    <div class="form-group">
                                                        <label class="label-title">Order notes (optional)</label>
                                                        <textarea name="notes" placeholder="Notes about your order, e.g. special notes for delivery." class="form-control @error('notes') is-invalid @enderror" rows="4">{{ old('notes') }}</textarea>
                                                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                    <div class="form-group d-flex align-items-center">
                                                        <input class="form-check-input make-default-checkbox" type="checkbox" name="make_default" value="1" id="makeDefaultCheckbox" {{ old('make_default') ? 'checked' : '' }}>
                                                        <label class="form-check-label" for="makeDefaultCheckbox">Make this my default delivery address</label>
                                                    </div>
                                                    @if(!auth()->check())
                                                        <div class="form-group d-flex align-items-center">
                                                            <input type="checkbox" name="create_account" value="1" class="form-check-input make-default-checkbox" id="create_account">
                                                            <label class="form-check-label" for="create_account">Create an account?</label>
                                                        </div>
                                                    @endif
                                                </div>
                                            </section>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="button" class="btn btn-primary" id="saveAddressAndClose">Save &amp; Continue</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 side-bar">
                        <h5 class="title m-b15" style="padding: 15px;">Your Order</h5>
                        <div class="order-detail sticky-top pd-15">
                            @php $subtotal = 0; @endphp
                            @foreach($cartSummaryItems as $item)
                                @php $subtotal += $item['total']; @endphp
                                <div class="cart-item style-1">
                                    <div class="dz-media">
                                        <img src="{{ $item['image_path'] ? asset('storage/'.$item['image_path']) : asset('home/images/shop/shop-cart/pic1.jpg') }}" alt="{{ $item['name'] }}">
                                    </div>
                                    <div class="dz-content">
                                        <h6 class="title mb-0">{{ $item['name'] }} <span class="text-muted">x{{ $item['qty'] }}</span></h6>
                                        <span class="price">₦{{ number_format($item['total'], 2) }}</span>
                                        @if(!$item['has_product'] && $item['unit_hint'])
                                            <div class="text-muted small">{{ $item['unit_hint'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <table>
                                <tbody>
                                    <tr class="subtotal">
                                        <td>Subtotal</td>
                                        <td class="price">₦{{ number_format($subtotal, 2) }}</td>
                                    </tr>
                                    <tr class="shipping">
                                        <td>Shipping</td>
                                        <td class="price" id="checkoutShippingFee">₦0.00</td>
                                    </tr>
                                    <tr class="vat">
                                        <td>VAT ({{ number_format($vatPercentage, 1) }}%)</td>
                                        <td class="price" id="checkoutVat">₦0.00</td>
                                    </tr>
                                    <tr class="total">
                                        <td><strong>Total</strong></td>
                                        <td class="price"><strong id="checkoutTotal">₦{{ number_format($subtotal, 2) }}</strong></td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- <h5 class="mt-4 mb-3">Payment Method</h5>
                            @foreach($paymentMethods as $method)
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="payment_method_id" id="payment_{{ $method->id }}" value="{{ $method->id }}" {{ old('payment_method_id') == $method->id ? 'checked' : ($loop->first ? 'checked' : '') }} required>
                                <label class="form-check-label" for="payment_{{ $method->id }}">
                                    <strong>{{ $method->name }}</strong>
                                    @if($method->description)
                                    <br><small class="text-muted">{{ $method->description }}</small>
                                    @endif
                                </label>
                            </div>
                            @endforeach
                            @error('payment_method_id')<div class="text-danger small">{{ $message }}</div>@enderror -->

                            <!-- <p class="text mt-3">Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our <a href="javascript:void(0);">privacy policy.</a></p> -->
                            
                            <!-- Live First Program Option -->
                            @if($canUseLiveFirst)
                                <div class="alert alert-success d-flex align-items-center mt-3 mb-3" role="alert">
                                    <i class="fa fa-badge-check me-2"></i>
                                    <div class="flex-grow-1">
                                        <strong>Live First Program Available</strong>
                                        <p class="mb-0 small">Pay only 10% now, get your items, and pay the rest over 6 months!</p>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#liveFirstModal">
                                    <i class="fa fa-credit-card me-2"></i> USE LIVE FIRST PROGRAM
                                </button>
                                <div class="text-center mb-2 text-muted small">OR</div>
                            @elseif($liveFirstStatus->value === 'not_enrolled')
                                <div class="alert alert-info d-flex align-items-center mt-3 mb-3" role="alert">
                                    <i class="fa fa-info-circle me-2"></i>
                                    <div class="flex-grow-1">
                                        <strong>Want to Shop on Credit?</strong>
                                        <p class="mb-0 small">Join our Live First program and pay only 10% upfront!</p>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#liveFirstModal">
                                    <i class="fa fa-rocket me-2"></i> LEARN ABOUT LIVE FIRST
                                </button>
                                <div class="text-center mb-2 text-muted small">OR</div>
                            @elseif($liveFirstStatus->value === 'pending_verification')
                                <div class="alert alert-warning d-flex align-items-center mt-3 mb-3" role="alert">
                                    <i class="fa fa-clock me-2"></i>
                                    <div>
                                        <strong>Live First Application Pending</strong>
                                        <p class="mb-0 small">Your application is being reviewed. You'll be able to use Live First once approved.</p>
                                    </div>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-secondary w-100 mt-3">PLACE ORDER (FULL PAYMENT)</button>
                        </div>
                    </div>
                    
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Live First Modal -->
<div class="modal fade" id="liveFirstModal" tabindex="-1" aria-labelledby="liveFirstModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="liveFirstModalLabel">
                    <i class="fa fa-badge-check text-success me-2"></i> Live First Program
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if($canUseLiveFirst)
                    <div class="alert alert-success mb-4">
                        <h6 class="fw-bold mb-2"><i class="fa fa-check-circle me-1"></i> You're Eligible!</h6>
                        <p class="mb-0">You can use the Live First program for this purchase.</p>
                    </div>

                    <h6 class="fw-semibold mb-3">How it works:</h6>
                    <ol class="mb-4">
                        <li class="mb-2"><strong>Pay 10% now</strong> - Only ₦<span id="modalDownPayment">0</span> upfront</li>
                        <li class="mb-2"><strong>Get your items immediately</strong> - We'll deliver your order right away</li>
                        <li class="mb-2"><strong>Pay the rest over 6 months</strong> - Automatic salary deduction of ₦<span id="modalMonthlyPayment">0</span>/month</li>
                    </ol>

                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-3">Order Summary</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Cart Total:</span>
                                <strong>₦<span id="modalCartTotal">0</span></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-success">Pay Now (10%):</span>
                                <strong class="text-success">₦<span id="modalPayNow">0</span></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Balance (over 6 months):</span>
                                <span>₦<span id="modalBalance">0</span></span>
                            </div>
                        </div>
                    </div>

                    <form id="liveFirstCheckoutForm" method="POST" action="{{ route('checkout.live-first', ['store_slug' => $store->slug]) }}">
                        @csrf
                        <input type="hidden" name="selected_address_id" id="lf_selected_address_id">
                        <input type="hidden" name="recipient_name" id="lf_recipient_name">
                        <input type="hidden" name="recipient_phone" id="lf_recipient_phone">
                        <input type="hidden" name="company_name" id="lf_company_name">
                        <input type="hidden" name="street_address" id="lf_street_address">
                        <input type="hidden" name="apartment" id="lf_apartment">
                        <input type="hidden" name="zip_code" id="lf_zip_code">
                        <input type="hidden" name="map_link" id="lf_map_link">
                        <input type="hidden" name="delivery_route_id" id="lf_delivery_route_id">
                        <input type="hidden" name="save_address" id="lf_save_address">
                        <input type="hidden" name="set_default" id="lf_set_default">
                        <input type="hidden" name="note" id="lf_note">
                        
                        <div class="form-control mb-3">
                            <input class="form-check-input make-default-checkbox" type="checkbox" id="liveFirstTerms" required>
                            <label class="form-check-label" for="liveFirstTerms">
                                I understand and agree to the automatic salary deduction for 6 months to complete payment
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fa fa-lock me-2"></i> PROCEED WITH LIVE FIRST (Pay ₦<span id="modalPayNowBtn">0</span>)
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                @else
                    <div class="text-center py-4">
                        <i class="fa fa-rocket text-success" style="font-size: 3rem;"></i>
                        <h5 class="fw-bold mt-3 mb-2">Welcome to Live First!</h5>
                        <p class="text-muted mb-4">Shop now, pay only 10% upfront, and spread the rest over 6 months</p>
                    </div>

                    <h6 class="fw-semibold mb-3">Program Benefits:</h6>
                    <ul class="mb-4">
                        <li class="mb-2"><i class="fa fa-check text-success me-2"></i> Pay only 10% upfront</li>
                        <li class="mb-2"><i class="fa fa-check text-success me-2"></i> Get your items immediately</li>
                        <li class="mb-2"><i class="fa fa-check text-success me-2"></i> Flexible 6-month payment plan</li>
                        <li class="mb-2"><i class="fa fa-check text-success me-2"></i> Build your credit history</li>
                    </ul>

                    <div class="d-grid gap-2">
                        <a href="{{ route('home.live-first.index', ['store_slug' => $store->slug]) }}" class="btn btn-success btn-lg">
                            <i class="fa fa-arrow-right me-2"></i> ENROLL IN LIVE FIRST
                        </a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Maybe Later</button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const subtotal = parseFloat(@json($subtotal));
    const vatPercentage = parseFloat(@json($vatPercentage));
    const areasData = @json($areasByState ?? []);
    const addressDataset = @json($addressDataset ?? []);
    const prefillAddress = @json($prefillAddress ?? []);
    const newAddressDefaults = @json($newAddressDefaults ?? []);
    const hasOldInput = Boolean(@json($hasOldInput));
    const oldSelectedAddress = @json($oldSelectedAddress);
    const oldDeliveryRouteId = @json(old('delivery_route_id'));

    const form = document.getElementById('checkoutForm');
    if (!form) return;

    const stateSelect = document.getElementById('deliveryStateSelect');
    const areaSelect = document.getElementById('deliveryAreaSelect');
    const deliveryInfo = document.getElementById('deliveryInfo');
    const deliveryFeeDisplay = document.getElementById('deliveryFeeDisplay');
    const deliveryDaysDisplay = document.getElementById('deliveryDaysDisplay');
    const checkoutShippingFee = document.getElementById('checkoutShippingFee');
    const checkoutVat = document.getElementById('checkoutVat');
    const checkoutTotal = document.getElementById('checkoutTotal');
    const radios = form.querySelectorAll('input[name="selected_address_id"]');
    const addressFields = form.querySelectorAll('[data-address-field]');
    const addressSummary = document.getElementById('addressDetailsPlaceholder');
    const addNewAddressBtn = document.getElementById('addNewAddressBtn');
    const saveAddressBtn = document.getElementById('saveAddressAndClose');
    const modalElement = document.getElementById('newAddressModal');
    const newAddressRadio = document.getElementById('addressNewOption');
    const addressCards = form.querySelectorAll('.saved-address-option');

    let modalInstance = null;
    if (modalElement) {
        if (window.bootstrap?.Modal) {
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        } else if (window.jQuery) {
            modalInstance = {
                hide: () => window.jQuery(modalElement).modal('hide'),
            };
        }
    }

    const routeMap = {};
    Object.entries(areasData).forEach(([state, list]) => {
        list.forEach(area => {
            routeMap[String(area.id)] = {
                ...area,
                state
            };
        });
    });

    function formatNgn(amount) {
        return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(amount);
    }

    function updateTotals(shippingNaira) {
        const vat = (subtotal + shippingNaira) * (vatPercentage / 100);
        const total = subtotal + shippingNaira + vat;

        if (checkoutShippingFee) checkoutShippingFee.textContent = formatNgn(shippingNaira);
        if (checkoutVat) checkoutVat.textContent = formatNgn(vat);
        if (checkoutTotal) checkoutTotal.textContent = formatNgn(total);
    }

    function renderSummary(data) {
        if (!addressSummary) {
            return;
        }

        if (!data) {
            addressSummary.innerHTML = 'Select an existing address or add a new one to provide delivery details.';
            return;
        }

        const lines = [];

        if (data.recipient_name || data.recipient_phone) {
            const name = data.recipient_name ? `<strong>${data.recipient_name}</strong>` : '';
            const phone = data.recipient_phone ? `<span class="ms-2">${data.recipient_phone}</span>` : '';
            lines.push(`<div>${name}${phone}</div>`);
        }

        const areaLabel = data.delivery_area || data.area || '';
        const stateLabel = data.delivery_state || data.state || '';
        const addressParts = [data.street_address, data.apartment, areaLabel, stateLabel]
            .filter(Boolean)
            .join(', ');
        if (addressParts) {
            lines.push(`<div>${addressParts}</div>`);
        }

        const area = data.delivery_area || data.area;
        if (area) {
            lines.push(`<div class="text-muted small">Area: ${area}</div>`);
        }

        if (data.delivery_fee) {
            const feeDisplay = formatNgn((data.delivery_fee || 0) / 100);
            const eta = data.delivery_days ? ` • ETA: ${data.delivery_days} day(s)` : '';
            lines.push(`<div class="text-muted small">Delivery Fee: ${feeDisplay}${eta}</div>`);
        }

        addressSummary.innerHTML = lines.join('') || 'Select an existing address or add a new one to provide delivery details.';
    }

    function isNewAddressSelected() {
        return !!(newAddressRadio && newAddressRadio.checked);
    }

    function showDeliveryInfo(feeKobo, days) {
        if (!deliveryInfo) {
            updateTotals(0);
            if (isNewAddressSelected()) {
                updateNewAddressSummary(feeKobo, days);
            }
            return;
        }

        const hasFeeValue = feeKobo !== null && feeKobo !== undefined;
        const feeNaira = hasFeeValue ? (feeKobo / 100) : 0;

        if (deliveryFeeDisplay) {
            deliveryFeeDisplay.textContent = hasFeeValue ? formatNgn(feeNaira) : '—';
        }
        if (deliveryDaysDisplay) {
            deliveryDaysDisplay.textContent = days ? `${days} day(s)` : '—';
        }

        deliveryInfo.style.display = hasFeeValue ? 'block' : 'none';
        updateTotals(feeNaira);

        if (isNewAddressSelected()) {
            updateNewAddressSummary(feeKobo, days);
        }
    }

    function buildAreas(state) {
        if (!areaSelect) {
            return;
        }

        areaSelect.innerHTML = '<option value="">Select Area</option>';
        areaSelect.disabled = true;

        if (!state || !areasData[state]) {
            return;
        }

        areasData[state].forEach(area => {
            const opt = document.createElement('option');
            opt.value = area.id;
            opt.textContent = area.area;
            opt.dataset.fee = area.fee;
            opt.dataset.days = area.days;
            areaSelect.appendChild(opt);
        });

        areaSelect.disabled = false;
    }

    function fillAddressFields(values) {
        addressFields.forEach(field => {
            const name = field.name;
            if (Object.prototype.hasOwnProperty.call(values, name)) {
                field.value = values[name] ?? '';
            }
        });
    }

    function applyRouteSelection(state, routeId, feeKobo, days) {
        if (stateSelect) {
            stateSelect.value = state || '';
        }

        if (stateSelect) {
            buildAreas(stateSelect.value || state || '');
        }

        if (areaSelect && routeId) {
            areaSelect.value = routeId;
            if (areaSelect.value === '' && state) {
                buildAreas(state);
                areaSelect.value = routeId;
            }
        }

        if ((!feeKobo || feeKobo === 0) && routeId && routeMap[String(routeId)]) {
            const mapped = routeMap[String(routeId)];
            feeKobo = mapped.fee;
            days = mapped.days;
            if (!state) {
                if (stateSelect) {
                    stateSelect.value = mapped.state;
                    buildAreas(mapped.state);
                }
                if (areaSelect) {
                    areaSelect.value = routeId;
                }
            }
        }
        showDeliveryInfo(feeKobo, days);
    }

    function applySavedAddress(addressId) {
        const data = addressDataset[addressId];
        if (!data) {
            return;
        }

        fillAddressFields(data);
        applyRouteSelection(data.delivery_state || data.state, data.delivery_route_id, data.delivery_fee, data.delivery_days);
        renderSummary(data);
    }

    function collectNewAddressData(extra = {}) {
        const payload = {};
        addressFields.forEach(field => {
            payload[field.name] = field.value;
        });

        payload.delivery_state = stateSelect ? stateSelect.value : payload.delivery_state;
        payload.delivery_area = '';
        payload.delivery_fee = extra.delivery_fee ?? null;
        payload.delivery_days = extra.delivery_days ?? null;

        if (areaSelect && areaSelect.value) {
            const entry = routeMap[String(areaSelect.value)];
            if (entry) {
                payload.delivery_area = entry.area;
                payload.delivery_fee = entry.fee;
                payload.delivery_days = entry.days;
                payload.delivery_state = entry.state;
            }
        }

        return payload;
    }

    function updateNewAddressSummary(feeKobo, days) {
        const data = collectNewAddressData({
            delivery_fee: feeKobo ?? null,
            delivery_days: days ?? null,
        });
        renderSummary(data);
    }

    function resetToNewAddress() {
        fillAddressFields(newAddressDefaults);
        if (stateSelect) {
            stateSelect.value = '';
        }
        buildAreas('');
        if (areaSelect) {
            areaSelect.value = '';
        }
        showDeliveryInfo(null, null);
        renderSummary(null);
    }

    radios.forEach(radio => {
        radio.addEventListener('change', () => {
            const value = radio.value;
            if (value === 'new') {
                renderSummary(null);
                updateCardSelection();
                return;
            }
            applySavedAddress(value);
            updateCardSelection();
        });
    });

    if (areaSelect) {
        areaSelect.addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const feeKobo = parseInt(selectedOption.dataset.fee || '0', 10);
                const days = selectedOption.dataset.days || null;
                showDeliveryInfo(feeKobo, days);
            } else {
                showDeliveryInfo(null, null);
            }
        });
    }

    if (stateSelect) {
        stateSelect.addEventListener('change', function () {
            const state = this.value;
            buildAreas(state);
            if (areaSelect) {
                areaSelect.value = '';
            }
            showDeliveryInfo(null, null);
        });
    }

    if (addNewAddressBtn) {
        addNewAddressBtn.addEventListener('click', () => {
            if (newAddressRadio) {
                newAddressRadio.checked = true;
            }
            resetToNewAddress();
        });
    }

    if (saveAddressBtn) {
        saveAddressBtn.addEventListener('click', async (event) => {
            event.preventDefault();

            // Validate required fields before saving
            const deliveryRouteId = document.querySelector('[name="delivery_route_id"]')?.value;
            const recipientName = document.querySelector('[name="recipient_name"]')?.value;
            const recipientPhone = document.querySelector('[name="recipient_phone"]')?.value;
            const streetAddress = document.querySelector('[name="street_address"]')?.value;

            if (!deliveryRouteId) {
                alert('Please select your delivery location (State and Area)');
                return;
            }

            if (!recipientName || !recipientPhone || !streetAddress) {
                alert('Please fill in all required fields');
                return;
            }

            // Disable button and show loading
            const originalText = saveAddressBtn.innerHTML;
            saveAddressBtn.disabled = true;
            saveAddressBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            try {
                // Collect address data
                const addressData = {
                    label: document.querySelector('[name="label"]')?.value || '',
                    recipient_name: recipientName,
                    recipient_phone: recipientPhone,
                    company_name: document.querySelector('[name="company_name"]')?.value || '',
                    street_address: streetAddress,
                    apartment: document.querySelector('[name="apartment"]')?.value || '',
                    zip_code: document.querySelector('[name="zip_code"]')?.value || '',
                    map_link: document.querySelector('[name="map_link"]')?.value || '',
                    delivery_route_id: deliveryRouteId,
                    set_default: document.querySelector('[name="set_default"]')?.checked || false,
                };

                // Send AJAX request to save address
                const response = await fetch('{{ route("checkout.save-address", ["store_slug" => $store->slug]) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(addressData),
                });

                const result = await response.json();

                if (result.success) {
                    // Reload page to reflect new address
                    window.location.reload();
                } else {
                    // Display validation errors if available
                    let errorMessage = result.message || 'Failed to save address. Please try again.';
                    
                    if (result.errors) {
                        const errorList = Object.entries(result.errors)
                            .map(([field, messages]) => `${field}: ${messages.join(', ')}`)
                            .join('\n');
                        errorMessage += '\n\nValidation Errors:\n' + errorList;
                    }
                    
                    console.error('Address save error:', result);
                    alert(errorMessage);
                    saveAddressBtn.disabled = false;
                    saveAddressBtn.innerHTML = originalText;
                }
            } catch (error) {
                console.error('Error saving address:', error);
                alert('An error occurred while saving the address. Please try again.');
                saveAddressBtn.disabled = false;
                saveAddressBtn.innerHTML = originalText;
            }
        });
    }

    function updateCardSelection() {
        addressCards.forEach(card => {
            const radio = card.querySelector('input[type="radio"]');
            if (!radio) return;
            if (radio.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });

        if (newAddressRadio && newAddressRadio.checked) {
            addressSummary.innerHTML = 'Review your entry below and place the order once ready.';
        }
    }

    updateCardSelection();

    function initialise() {
        if (!hasOldInput) {
            const selected = form.querySelector('input[name="selected_address_id"]:checked');
            if (selected && selected.value && selected.value !== 'new') {
                applySavedAddress(selected.value);
                return;
            }

            if (prefillAddress && Object.keys(prefillAddress).length) {
                fillAddressFields(prefillAddress);
                applyRouteSelection(prefillAddress.delivery_state || prefillAddress.state, prefillAddress.delivery_route_id, prefillAddress.delivery_fee, prefillAddress.delivery_days);
                renderSummary(prefillAddress);
            } else {
                resetToNewAddress();
            }
        } else {
            if (oldSelectedAddress && oldSelectedAddress !== 'new' && addressDataset[oldSelectedAddress]) {
                const data = addressDataset[oldSelectedAddress];
                applySavedAddress(oldSelectedAddress);
                renderSummary(data);
            } else if (oldDeliveryRouteId && routeMap[String(oldDeliveryRouteId)]) {
                const mapped = routeMap[String(oldDeliveryRouteId)];
                if (stateSelect) {
                    stateSelect.value = mapped.state;
                }
                buildAreas(mapped.state);
                if (areaSelect) {
                    areaSelect.value = String(oldDeliveryRouteId);
                }
                showDeliveryInfo(mapped.fee, mapped.days);
                updateNewAddressSummary(mapped.fee, mapped.days);
                renderSummary(collectNewAddressData({ delivery_fee: mapped.fee, delivery_days: mapped.days }));
            } else {
                showDeliveryInfo(null, null);
                renderSummary(collectNewAddressData());
            }
        }
    }

    initialise();
})();

// Live First Modal Calculations
@if($canUseLiveFirst)
(function() {
    const liveFirstModal = document.getElementById('liveFirstModal');
    if (!liveFirstModal) return;

    liveFirstModal.addEventListener('show.bs.modal', function() {
        // Get current cart total from the checkout page
        const checkoutTotalElem = document.getElementById('checkoutTotal');
        if (!checkoutTotalElem) return;

        const totalText = checkoutTotalElem.textContent.replace(/[^\d.]/g, '');
        const cartTotal = parseFloat(totalText) || 0;

        const downPayment = cartTotal * 0.10;
        const balance = cartTotal * 0.90;
        const monthlyPayment = balance / 6;

        // Update modal values
        document.getElementById('modalCartTotal').textContent = cartTotal.toLocaleString('en-NG', {minimumFractionDigits: 2});
        document.getElementById('modalDownPayment').textContent = downPayment.toLocaleString('en-NG', {minimumFractionDigits: 2});
        document.getElementById('modalPayNow').textContent = downPayment.toLocaleString('en-NG', {minimumFractionDigits: 2});
        document.getElementById('modalPayNowBtn').textContent = downPayment.toLocaleString('en-NG', {minimumFractionDigits: 2});
        document.getElementById('modalBalance').textContent = balance.toLocaleString('en-NG', {minimumFractionDigits: 2});
        document.getElementById('modalMonthlyPayment').textContent = monthlyPayment.toLocaleString('en-NG', {minimumFractionDigits: 2});

        // Copy form data from main checkout to Live First form
        const mainForm = document.getElementById('checkoutForm');
        if (!mainForm) return;

        // Get selected address type
        const selectedAddressRadio = mainForm.querySelector('input[name="selected_address_id"]:checked');
        if (selectedAddressRadio) {
            document.getElementById('lf_selected_address_id').value = selectedAddressRadio.value;
        }

        // Copy all address fields
        const fields = [
            'recipient_name', 'recipient_phone', 'company_name',
            'street_address', 'apartment', 'zip_code', 'map_link',
            'delivery_route_id', 'note'
        ];

        fields.forEach(field => {
            const input = mainForm.querySelector(`[name="${field}"]`);
            const lfInput = document.getElementById(`lf_${field}`);
            if (input && lfInput) {
                lfInput.value = input.value || '';
            }
        });

        // Copy checkboxes
        const saveAddress = mainForm.querySelector('[name="save_address"]');
        const setDefault = mainForm.querySelector('[name="set_default"]');
        if (saveAddress) {
            document.getElementById('lf_save_address').value = saveAddress.checked ? '1' : '0';
        }
        if (setDefault) {
            document.getElementById('lf_set_default').value = setDefault.checked ? '1' : '0';
        }
    });
})();
@endif
</script>
@endsection
