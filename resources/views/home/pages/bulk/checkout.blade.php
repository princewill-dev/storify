@extends('home.layout')
@section('title', 'Bulk Order Checkout')

@section('content')
<br>
<br>
<br>
<br>
<div class="page-content">
    <div class="container py-5">
        <h2 class="mb-4">Bulk Order Checkout</h2>

        <form action="{{ route('bulk.checkout.submit', $store->slug) }}" method="POST">
            @csrf

            <div class="row">
                <!-- Cart Summary -->
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fa fa-shopping-cart"></i> Order Summary</h5>
                        </div>
                        <div class="card-body">
                            @if(!empty($cart['items']))
                                <h6 class="mb-3">Bulk Products</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-end">Price</th>
                                                <th class="text-end">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($cart['items'] as $item)
                                            <tr>
                                                <td>
                                                    <strong>{{ $item['name'] }}</strong><br>
                                                    <small class="text-muted">Code: {{ $item['product_code'] }}</small>
                                                </td>
                                                <td class="text-center">{{ number_format($item['quantity']) }}</td>
                                                <td class="text-end">{{ $item['currency_symbol'] }}{{ number_format($item['unit_price'], 2) }}</td>
                                                <td class="text-end">{{ $item['currency_symbol'] }}{{ number_format($item['subtotal'], 2) }}</td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if(!empty($cart['custom_items']))
                                <h6 class="mb-3 mt-4">Custom Product Requests</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Description</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-end">Budgeted Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($cart['custom_items'] as $item)
                                            <tr>
                                                <td>{{ $item['name'] }}</td>
                                                <td class="text-center">{{ number_format($item['quantity']) }}</td>
                                                <td class="text-end">₦{{ number_format($item['budgeted_amount'], 2) }}</td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Delivery Address Selection -->
                    <div class="card">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fa fa-truck"></i> Delivery Address</h5>
                            <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addAddressModal">
                                <i class="fa fa-plus p-2"></i> Add New
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row" id="address-list">
                                @if($deliveryAddresses->count() > 0)
                                    @foreach($deliveryAddresses as $address)
                                        <div class="col-md-6 mb-3 address-item">
                                            <div class="form-check card-radio">
                                                <input class="form-check-input" type="radio" name="delivery_address_id" 
                                                    id="address_{{ $address->id }}" value="{{ $address->id }}" 
                                                    {{ $address->is_default ? 'checked' : '' }} required>
                                                <label class="form-check-label w-100" for="address_{{ $address->id }}">
                                                    <div class="border p-3 rounded position-relative address-card">
                                                        <div class="d-flex justify-content-between">
                                                            <strong>{{ $address->label ?? 'Address' }}</strong>
                                                            @if($address->is_default)
                                                                <span class="badge bg-primary">Default</span>
                                                            @endif
                                                        </div>
                                                        <div class="mt-2 text-muted small">
                                                            <div><strong>{{ $address->recipient_name }}</strong></div>
                                                            <div>{{ $address->street_address }}</div>
                                                            @if($address->apartment)
                                                                <div>{{ $address->apartment }}</div>
                                                            @endif
                                                            <div>{{ $address->deliveryRoute->area ?? '' }}, {{ $address->deliveryRoute->state ?? '' }}</div>
                                                            <div>{{ $address->recipient_phone }}</div>
                                                        </div>
                                                        <div class="check-icon text-success position-absolute top-0 end-0 m-2" style="display: none;">
                                                            <i class="fa fa-check-circle fa-lg"></i>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="col-12" id="no-address-alert">
                                        <div class="alert alert-warning">
                                            You don't have any saved delivery addresses. Please add one before proceeding.
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#addAddressModal" class="alert-link">Add Delivery Address</a>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3">
                                <label class="form-label">Order Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions or requirements..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Total Sidebar -->
                <div class="col-lg-4">
                    <div class="card ">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0 text-white">Order Total</h5>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-8">Subtotal:</dt>
                                <dd class="col-sm-4 text-end">₦{{ number_format($subtotal, 2) }}</dd>
                                <dt class="col-sm-8 text-muted">Shipping:</dt>
                                <dd class="col-sm-4 text-end text-muted" id="bulkShippingFee">₦0.00</dd>

                                <dt class="col-sm-8 text-muted">Tax ({{ number_format($vatPercentage, 1) }}%):</dt>
                                <dd class="col-sm-4 text-end text-muted" id="bulkTax">₦0.00</dd>
                            </dl>
                            <hr>
                            <dl class="row mb-0">
                                <dt class="col-sm-7 h6">Total:</dt>
                                <dd class="col-sm-5 text-end h6" id="bulkTotal">₦{{ number_format($subtotal, 2) }}</dd>
                            </dl>

                            <div class="alert alert-info mt-3 small">
                                <i class="fa fa-info-circle"></i>
                                This is an estimated total. Final pricing will be confirmed after admin review.
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                                <i class="fa fa-check p-2"></i> Submit Bulk Order
                            </button>

                            <div class="text-center mt-3">
                                <small class="text-muted">No payment required at this time</small>
                            </div>
                        </div>
                    </div>

                    <!-- Process Info -->
                    <div class="card mt-3">
                        <div class="card-body">
                            <h6 class="card-title">What happens next?</h6>
                            <ol class="small mb-0 ps-3">
                                <li>Your order will be reviewed by our team</li>
                                <li>We'll confirm pricing and availability</li>
                                <li>You'll receive a payment link via email</li>
                                <li>Order will be processed after payment</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    </div>
</div>

<!-- Add Address Modal -->
<div class="modal fade" id="addAddressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Delivery Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addAddressForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Address Label (Optional)</label>
                        <input type="text" class="form-control" name="label" placeholder="e.g., Home, Office">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recipient Name</label>
                        <input type="text" class="form-control" name="recipient_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Recipient Phone</label>
                        <input type="tel" class="form-control" name="recipient_phone" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Street Address</label>
                        <textarea class="form-control" name="street_address" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Apartment/Suite (Optional)</label>
                        <input type="text" class="form-control" name="apartment">
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">State *</label>
                            <select class="form-select" name="delivery_state" id="deliveryStateSelect" required>
                                <option value="">Select State</option>
                                @foreach($states as $state)
                                    <option value="{{ $state }}">{{ $state }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Area *</label>
                            <select class="form-select" name="delivery_route_id" id="deliveryAreaSelect" required disabled>
                                <option value="">Select Area</option>
                            </select>
                        </div>
                    </div>
                    <div id="deliveryInfoCard" class="alert alert-info mb-3" style="display:none;">
                        <div class="d-flex justify-content-between mb-1">
                            <span><strong>Delivery Fee:</strong></span>
                            <span id="modalDeliveryFee">—</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span><strong>Delivery Time:</strong></span>
                            <span id="modalDeliveryDays">—</span>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_default" id="is_default" value="1">
                        <label class="form-check-label" for="is_default">
                            Set as default address
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveAddressBtn">Save Address</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.address-card {
    cursor: pointer;
    transition: all 0.2s;
}
.card-radio input:checked + label .address-card {
    border-color: #198754 !important;
    background-color: #f8fff9;
    box-shadow: 0 0 0 1px #198754;
}
.card-radio input:checked + label .check-icon {
    display: block !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Data from backend
    const subtotal = parseFloat(@json($subtotal));
    const vatPercentage = parseFloat(@json($vatPercentage));
    const areasByState = @json($areasByState ?? []);
    const addressDataset = @json($addressDataset ?? []);

    // DOM Elements
    const addAddressForm = document.getElementById('addAddressForm');
    const saveAddressBtn = document.getElementById('saveAddressBtn');
    const addAddressModal = new bootstrap.Modal(document.getElementById('addAddressModal'));
    const stateSelect = document.getElementById('deliveryStateSelect');
    const areaSelect = document.getElementById('deliveryAreaSelect');
    const deliveryInfoCard = document.getElementById('deliveryInfoCard');
    const modalDeliveryFee = document.getElementById('modalDeliveryFee');
    const modalDeliveryDays = document.getElementById('modalDeliveryDays');
    
    // Order summary elements
    const bulkShippingFee = document.getElementById('bulkShippingFee');
    const bulkTax = document.getElementById('bulkTax');
    const bulkTotal = document.getElementById('bulkTotal');
    
    // Address radio inputs
    const addressRadios = document.querySelectorAll('input[name="delivery_address_id"]');

    // Helper functions
    function formatNgn(amount) {
        return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(amount);
    }

    function updateOrderTotals(shippingNaira) {
        const tax = (subtotal + shippingNaira) * (vatPercentage / 100);
        const total = subtotal + shippingNaira + tax;

        if (bulkShippingFee) bulkShippingFee.textContent = formatNgn(shippingNaira);
        if (bulkTax) bulkTax.textContent = formatNgn(tax);
        if (bulkTotal) bulkTotal.textContent = formatNgn(total);
    }

    function showDeliveryInfo(feeKobo, days) {
        const feeNaira = feeKobo ? (feeKobo / 100) : 0;
        
        if (modalDeliveryFee) {
            modalDeliveryFee.textContent = feeKobo ? formatNgn(feeNaira) : '—';
        }
        if (modalDeliveryDays) {
            modalDeliveryDays.textContent = days ? `${days} day(s)` : '—';
        }
        
        if (deliveryInfoCard) {
            deliveryInfoCard.style.display = feeKobo ? 'block' : 'none';
        }
    }

    function buildAreaOptions(state) {
        if (!areaSelect) return;
        
        areaSelect.innerHTML = '<option value="">Select Area</option>';
        areaSelect.disabled = true;
        
        if (!state || !areasByState[state]) {
            showDeliveryInfo(null, null);
            return;
        }
        
        areasByState[state].forEach(area => {
            const opt = document.createElement('option');
            opt.value = area.id;
            opt.textContent = area.area;
            opt.dataset.fee = area.fee;
            opt.dataset.days = area.days;
            areaSelect.appendChild(opt);
        });
        
        areaSelect.disabled = false;
    }

    // State select change handler
    if (stateSelect) {
        stateSelect.addEventListener('change', function() {
            buildAreaOptions(this.value);
        });
    }

    // Area select change handler
    if (areaSelect) {
        areaSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.dataset.fee) {
                const feeKobo = parseInt(selectedOption.dataset.fee);
                const days = parseInt(selectedOption.dataset.days);
                showDeliveryInfo(feeKobo, days);
            } else {
                showDeliveryInfo(null, null);
            }
        });
    }

    // Address selection change handler
    addressRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            const addressId = this.value;
            const addressData = addressDataset[addressId];
            
            if (addressData && addressData.delivery_fee) {
                const feeNaira = addressData.delivery_fee / 100;
                updateOrderTotals(feeNaira);
            } else {
                updateOrderTotals(0);
            }
            
            // Update visual selection
            document.querySelectorAll('.address-card').forEach(card => {
                card.classList.remove('selected');
            });
            const selectedLabel = this.closest('label');
            if (selectedLabel) {
                const card = selectedLabel.querySelector('.address-card');
                if (card) card.classList.add('selected');
            }
        });
    });

    // Initialize: if an address is selected, update totals
    const checkedRadio = document.querySelector('input[name="delivery_address_id"]:checked');
    if (checkedRadio) {
        const addressId = checkedRadio.value;
        const addressData = addressDataset[addressId];
        if (addressData && addressData.delivery_fee) {
            const feeNaira = addressData.delivery_fee / 100;
            updateOrderTotals(feeNaira);
        }
    }

    // Add address form submission
    addAddressForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Disable button and show loading state
        saveAddressBtn.disabled = true;
        saveAddressBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
        
        const formData = new FormData(addAddressForm);
        const data = Object.fromEntries(formData.entries());
        // Handle checkbox manually
        data.is_default = document.getElementById('is_default').checked ? 1 : 0;

        try {
            const response = await fetch('{{ route("account.addresses.store") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                // Add new address to list
                const addressList = document.getElementById('address-list');
                const noAddressAlert = document.getElementById('no-address-alert');
                
                if (noAddressAlert) {
                    noAddressAlert.remove();
                }

                const newAddressHtml = `
                    <div class="col-md-6 mb-3 address-item">
                        <div class="form-check card-radio">
                            <input class="form-check-input" type="radio" name="delivery_address_id" 
                                id="address_${result.address.id}" value="${result.address.id}" checked required>
                            <label class="form-check-label w-100" for="address_${result.address.id}">
                                <div class="border p-3 rounded position-relative address-card selected">
                                    <div class="d-flex justify-content-between">
                                        <strong>${result.address.label || 'Address'}</strong>
                                        ${result.address.is_default ? '<span class="badge bg-primary">Default</span>' : ''}
                                    </div>
                                    <div class="mt-2 text-muted small">
                                        <div><strong>${result.address.recipient_name}</strong></div>
                                        <div>${result.address.street_address}</div>
                                        ${result.address.apartment ? `<div>${result.address.apartment}</div>` : ''}
                                        <div>${result.address.delivery_route.area}, ${result.address.delivery_route.state}</div>
                                        <div>${result.address.recipient_phone}</div>
                                    </div>
                                    <div class="check-icon text-success position-absolute top-0 end-0 m-2">
                                        <i class="fa fa-check-circle fa-lg"></i>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                `;

                // If default, uncheck others
                if (result.address.is_default) {
                    document.querySelectorAll('input[name="delivery_address_id"]').forEach(input => {
                        input.checked = false;
                    });
                }

                addressList.insertAdjacentHTML('afterbegin', newAddressHtml);
                
                // Update addressDataset
                addressDataset[result.address.id] = {
                    delivery_route_id: result.address.delivery_route_id,
                    delivery_fee: result.address.delivery_route.fee,
                    delivery_days: result.address.delivery_route.delivery_days,
                    delivery_state: result.address.delivery_route.state,
                    delivery_area: result.address.delivery_route.area,
                };
                
                // Update totals with new address
                const feeNaira = result.address.delivery_route.fee / 100;
                updateOrderTotals(feeNaira);
                
                // Re-attach event listeners to new radio
                const newRadio = document.getElementById(`address_${result.address.id}`);
                if (newRadio) {
                    newRadio.addEventListener('change', function() {
                        const addressData = addressDataset[this.value];
                        if (addressData && addressData.delivery_fee) {
                            const feeNaira = addressData.delivery_fee / 100;
                            updateOrderTotals(feeNaira);
                        }
                        
                        document.querySelectorAll('.address-card').forEach(card => {
                            card.classList.remove('selected');
                        });
                        const card = this.closest('label').querySelector('.address-card');
                        if (card) card.classList.add('selected');
                    });
                }
                
                // Reset form and close modal
                addAddressForm.reset();
                areaSelect.disabled = true;
                areaSelect.innerHTML = '<option value="">Select Area</option>';
                deliveryInfoCard.style.display = 'none';
                addAddressModal.hide();
                
            } else {
                alert(result.message || 'Failed to add address');
            }
        } catch (error) {
            console.error('Error adding address:', error);
            alert('An error occurred. Please try again.');
        } finally {
            saveAddressBtn.disabled = false;
            saveAddressBtn.innerHTML = 'Save Address';
        }
    });
});
</script>
@endsection
