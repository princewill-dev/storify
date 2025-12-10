@extends('home.layout')

@section('title', 'Family Pack Checkout - ' . $store->name)

@section('content')
<div class="container py-5 mt-5">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('family-pack.index', ['store_slug' => $store->slug]) }}">Family Pack</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Checkout</li>
                </ol>
            </nav>
            <h1 class="h2 fw-bold mb-1">Configure Your Pack</h1>
            <p class="text-muted">Review your items and configure delivery preferences</p>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="row g-4">
        <!-- Main Form -->
        <div class="col-lg-8">
            <form action="{{ route('family-pack.submit', ['store_slug' => $store->slug]) }}" method="POST" id="checkoutForm">
                @csrf
                
                <!-- Subscription Settings (Recurring Only) -->
                <input type="hidden" name="pack_type" value="recurring">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">
                            <span class="badge bg-primary me-2">1</span>
                            Subscription Settings
                        </h5>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-medium">Delivery Frequency</label>
                                <select name="delivery_interval_id" class="form-select" id="deliveryInterval" required>
                                    @foreach($deliveryIntervals as $interval)
                                        <option value="{{ $interval->id }}" data-slug="{{ $interval->slug }}" data-days="{{ $interval->days_count }}">{{ $interval->name }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">How often you'll receive items</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delivery Details -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">
                            <span class="badge bg-primary me-2">2</span>
                            Delivery Information
                        </h5>
                        
                        <div class="mb-3">
                            <label class="form-label fw-medium">
                                <i class="bi bi-geo-alt me-1"></i>
                                Delivery Address
                            </label>
                            @if($deliveryAddresses->count() > 0)
                                <select name="delivery_address_id" class="form-select" required id="deliveryAddressSelect">
                                    <option value="">Choose your delivery address...</option>
                                    @foreach($deliveryAddresses as $address)
                                        <option value="{{ $address->id }}" 
                                                data-state="{{ optional($address->deliveryRoute)->state }}" 
                                                data-area="{{ optional($address->deliveryRoute)->area }}"
                                                data-route-id="{{ $address->delivery_route_id }}">
                                            {{ $address->street_address }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <div class="alert alert-warning d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-exclamation-circle me-2"></i>
                                        <div>No delivery address found.</div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newAddressModal">
                                        Add New Address
                                    </button>
                                </div>
                                <input type="hidden" name="delivery_address_id" required>
                            @endif
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">
                                    <i class="bi bi-map me-1"></i>
                                    State
                                </label>
                                <select id="stateSelect" class="form-select" onchange="filterAreasByState(this.value)">
                                    <option value="">Select State</option>
                                    @foreach($states as $state)
                                        <option value="{{ $state }}">{{ $state }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">
                                    <i class="bi bi-geo me-1"></i>
                                    Area
                                </label>
                                <select name="delivery_route_id" id="areaSelect" class="form-select" required disabled onchange="updateRouteDetails(this)">
                                    <option value="">Select Area</option>
                                </select>
                            </div>
                        </div>
                        <div id="routeDetails" class="alert alert-info py-2 px-3 mb-3" style="display: none;">
                            <div class="d-flex justify-content-between align-items-center">
                                <small><i class="bi bi-cash me-1"></i> Fee: <strong id="routeFee"></strong></small>
                                <small><i class="bi bi-clock me-1"></i> Delivery: <strong id="routeDays"></strong> days</small>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label fw-medium">
                                <i class="bi bi-chat-dots me-1"></i>
                                Special Instructions (Optional)
                            </label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Any special requests or delivery instructions?"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-3">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send me-2"></i>Submit Pack Request
                    </button>
                    <a href="{{ route('family-pack.index', ['store_slug' => $store->slug]) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Products
                    </a>
                </div>
            </form>
        </div>

        <!-- Cart Summary Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 100px;">
                <div class="card-header bg-light border-0 py-3">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-basket3 me-2"></i>Pack Summary
                    </h5>
                </div>
                
                <div class="card-body p-0">
                    @if(isset($cart['items']) && count($cart['items']) > 0)
                        <div class="list-group list-group-flush">
                            @foreach($cart['items'] as $key => $item)
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="flex-grow-1 me-2">
                                            <h6 class="mb-1">{{ $item['product_name'] }}</h6>
                                            <div class="d-flex align-items-center gap-2">
                                                <small class="text-muted">Qty: {{ $item['quantity'] }}</small>
                                                @if($item['is_custom'])
                                                    <span class="badge bg-info text-dark">Custom</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold text-primary mb-1">
                                                @if($item['is_custom'])
                                                    ₦{{ number_format($item['budgeted_amount'], 2) }}
                                                @else
                                                    ₦{{ number_format($item['unit_price'] * $item['quantity'], 2) }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        
                        <div class="p-3 bg-light">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-semibold">Subtotal (Est)</span>
                                <span class="h5 mb-0 text-primary">₦{{ number_format($subtotal, 2) }}</span>
                            </div>
                            <div class="alert alert-info mb-0 p-2">
                                <small class="d-flex align-items-start">
                                    <i class="bi bi-info-circle me-2 mt-1"></i>
                                    <span>Final price includes shipping, tax, and adjustments after admin review.</span>
                                </small>
                            </div>
                        </div>
                    @else
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-cart-x fs-1 d-block mb-3"></i>
                            <p class="mb-0">Your pack is empty</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Address Modal -->
<div class="modal fade checkout-address-modal" id="newAddressModal" tabindex="-1" aria-labelledby="newAddressModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="newAddressModalLabel">New Delivery Address</h5>
                    <p class="modal-subtitle text-muted small mb-0">Add a delivery location and recipient details.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="newAddressForm">
                    @csrf
                    <div class="checkout-modal-body">
                        <section class="section-block mb-4">
                            <div class="section-header mb-3">
                                <h6 class="fw-bold">Delivery location</h6>
                                <p class="section-helper text-muted small">Choose your state and delivery area.</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">State *</label>
                                    <select id="modalStateSelect" class="form-select" required onchange="filterModalAreas(this.value)">
                                        <option value="">Select State</option>
                                        @foreach($states as $state)
                                            <option value="{{ $state }}">{{ $state }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Area *</label>
                                    <select id="modalAreaSelect" name="delivery_route_id" class="form-select" required disabled>
                                        <option value="">Select Area</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="section-block">
                            <div class="section-header mb-3">
                                <h6 class="fw-bold">Recipient & address</h6>
                                <p class="section-helper text-muted small">Tell us who should receive this delivery.</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Address Label (optional)</label>
                                    <input type="text" name="label" class="form-control" placeholder="e.g., Home, Office">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Recipient Name *</label>
                                    <input type="text" name="recipient_name" value="{{ auth('customer')->user()->first_name }} {{ auth('customer')->user()->last_name }}" required class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Recipient Phone *</label>
                                    <input type="tel" name="recipient_phone" value="{{ auth('customer')->user()->phone }}" required class="form-control">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Street Address *</label>
                                    <input type="text" name="street_address" required class="form-control" placeholder="House number and street name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Apartment / Suite (optional)</label>
                                    <input type="text" name="apartment" class="form-control" placeholder="Apartment, suite, unit, etc.">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ZIP Code (optional)</label>
                                    <input type="text" name="zip_code" class="form-control">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_default" value="1" id="makeDefaultCheckbox" checked>
                                        <label class="form-check-label" for="makeDefaultCheckbox">Make this my default delivery address</label>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveNewAddress()">Save &amp; Continue</button>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .card-radio-label {
        display: block;
        cursor: pointer;
        margin: 0;
    }
    
    .card-radio-input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    
    .card-radio-content {
        border: 3px solid #e0e0e0;
        border-radius: 0.75rem;
        padding: 1.25rem;
        transition: all 0.3s ease;
        background: white;
        position: relative;
    }
    
    .card-radio-content:hover {
        border-color: #0d6efd;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
    }
    
    .radio-checkbox {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid #dee2e6;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: white;
        transition: all 0.3s ease;
    }
    
    .radio-checkbox i {
        font-size: 20px;
        color: transparent;
        transition: all 0.3s ease;
    }
    
    .card-radio-input:checked + .card-radio-content {
        border-color: #0d6efd;
        border-width: 4px;
        background: linear-gradient(135deg, #f8f9ff 0%, #e7f1ff 100%);
        box-shadow: 0 6px 20px rgba(13, 110, 253, 0.25);
    }
    
    .card-radio-input:checked + .card-radio-content .radio-checkbox {
        background: #0d6efd;
        border-color: #0d6efd;
    }
    
    .card-radio-input:checked + .card-radio-content .radio-checkbox i {
        color: white;
    }
    
    .sticky-top {
        z-index: 100;
    }
</style>
@endpush

@push('scripts')
<script>
    const deliveryRoutes = @json($deliveryRoutes);
    
    function filterAreasByState(state) {
        const areaSelect = document.getElementById('areaSelect');
        areaSelect.innerHTML = '<option value="">Select Area</option>';
        areaSelect.disabled = true;
        document.getElementById('routeDetails').style.display = 'none';
        
        if (!state) return;
        
        const matchingRoutes = deliveryRoutes.filter(r => r.state === state && r.active);
        
        if (matchingRoutes.length > 0) {
            matchingRoutes.forEach(route => {
                const option = document.createElement('option');
                option.value = route.id;
                option.dataset.fee = route.fee;
                option.dataset.days = route.delivery_days;
                option.textContent = route.area;
                areaSelect.appendChild(option);
            });
            areaSelect.disabled = false;
        }
    }

    function updateRouteDetails(select) {
        const option = select.options[select.selectedIndex];
        const detailsDiv = document.getElementById('routeDetails');
        
        if (option.value) {
            document.getElementById('routeFee').textContent = '₦' + parseFloat(option.dataset.fee).toLocaleString();
            document.getElementById('routeDays').textContent = option.dataset.days;
            detailsDiv.style.display = 'block';
        } else {
            detailsDiv.style.display = 'none';
        }
    }

    // Modal functions
    function filterModalAreas(state) {
        const areaSelect = document.getElementById('modalAreaSelect');
        areaSelect.innerHTML = '<option value="">Select Area</option>';
        areaSelect.disabled = true;
        
        if (!state) return;
        
        const matchingRoutes = deliveryRoutes.filter(r => r.state === state && r.active);
        
        matchingRoutes.forEach(route => {
            const option = document.createElement('option');
            option.value = route.id;
            option.textContent = route.area;
            areaSelect.appendChild(option);
        });
        areaSelect.disabled = false;
    }

    function saveNewAddress() {
        const form = document.getElementById('newAddressForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const formData = new FormData(form);
        const btn = document.querySelector('#newAddressModal .btn-primary');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('{{ route("account.addresses.store") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload(); // Reload to show new address
            } else {
                alert(data.message || 'Error saving address');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = originalText;
        });
    }

    // Auto-select route if address is selected
    document.addEventListener('DOMContentLoaded', function() {
        const addressSelect = document.getElementById('deliveryAddressSelect');
        const deliverySelect = document.getElementById('deliveryInterval');
        const paymentHidden = document.getElementById('paymentIntervalHidden');
        if (addressSelect && addressSelect.value) {
            const selectedOption = addressSelect.options[addressSelect.selectedIndex];
            const state = selectedOption.dataset.state;
            const routeId = selectedOption.dataset.routeId;
            
            if (state && routeId) {
                document.getElementById('stateSelect').value = state;
                filterAreasByState(state);
                document.getElementById('areaSelect').value = routeId;
                updateRouteDetails(document.getElementById('areaSelect'));
            }
        }
        
        // Listen for address changes to auto-fill route
        if (addressSelect) {
            addressSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const state = selectedOption.dataset.state;
                const routeId = selectedOption.dataset.routeId;
                
                if (state && routeId) {
                    document.getElementById('stateSelect').value = state;
                    filterAreasByState(state);
                    document.getElementById('areaSelect').value = routeId;
                    updateRouteDetails(document.getElementById('areaSelect'));
                }
            });
        }

        // Keep payment interval in sync with selected delivery interval
        function mapPaymentIntervalFromSlug(slug) {
            if (!slug) return '';
            const s = slug.toLowerCase();
            if (s === 'weekly' || s === 'week' || s === '7_days' || s === '7days') return 'weekly';
            if (s === 'monthly' || s === 'month' || s === '30_days' || s === '30days') return 'monthly';
            if (s === '6_months' || s === '6-months' || s === 'six_months' || s === 'six-months') return '6_months';
            if (s === '12_months' || s === '12-months' || s === 'twelve_months' || s === 'twelve-months') return '12_months';
            return '';
        }

        function syncPaymentToDelivery() {
            if (!deliverySelect || !paymentHidden) return;
            const opt = deliverySelect.options[deliverySelect.selectedIndex];
            const slug = opt ? opt.dataset.slug : '';
            paymentHidden.value = mapPaymentIntervalFromSlug(slug);
        }

        if (deliverySelect) {
            deliverySelect.addEventListener('change', syncPaymentToDelivery);
        }

        // Recurring-only: no extra toggling required
    });

    // Recurring-only: no toggle function needed
</script>
@endpush
@endsection
