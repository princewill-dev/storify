@extends('home.layout')

@section('title', 'Family Pack - ' . $store->name)

@section('content')
<br>
<br>
<br>
<div class="container py-5">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="display-5 fw-bold">Family Pack</h1>
            <p class="lead text-muted">Build your custom family pack from {{ $store->name }}</p>
        </div>
        <!-- Removed top button, moved to floating icon -->
    </div>

    <!-- Floating Pack Icon -->
    <div id="floatingPackIcon" class="position-fixed bottom-0 end-0 m-4" style="z-index: 1050; cursor: pointer;">
        <button class="btn btn-primary btn-lg rounded-circle shadow-lg position-relative" style="width: 60px; height: 60px;">
            <i class="fa-solid fa-cart-shopping fs-4"></i>
            <span id="packCountBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none;">
                0
                <span class="visually-hidden">items in pack</span>
            </span>
        </button>
    </div>

    <!-- Pack Drawer (Offcanvas) -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="packDrawer" aria-labelledby="packDrawerLabel">
        <div class="offcanvas-header bg-primary text-white">
            <h5 class="offcanvas-title" id="packDrawerLabel"><i class="bi bi-box-seam me-2"></i>Your Family Pack</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
            <div id="packItemsList" class="flex-grow-1 overflow-auto mb-3">
                <!-- Items will be populated here via JS -->
                <div class="text-center text-muted mt-5">
                    <i class="bi bi-basket3 fs-1 d-block mb-2"></i>
                    <p>Your pack is empty</p>
                </div>
            </div>
            
            <div class="border-top pt-3">
                <div class="d-flex justify-content-between mb-3">
                    <span class="fw-bold">Subtotal (Est):</span>
                    <span class="fw-bold text-primary" id="packSubtotal">₦0.00</span>
                </div>
                <div class="d-grid gap-2">
                    <a href="{{ route('family-pack.checkout', ['store_slug' => $store->slug]) }}" class="btn btn-primary">
                        Proceed to Checkout
                    </a>
                    <button class="btn btn-outline-danger btn-sm" onclick="clearPack()">Clear Pack</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Products Grid -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Available Products</h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        @foreach($products as $product)
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 border-0 shadow-sm product-card">
                                    <div class="position-relative">
                                        @php $primaryImage = $product->primaryImage(); @endphp
                                        @if($primaryImage)
                                            <img src="{{ asset('storage/' . $primaryImage->path) }}" class="card-img-top" alt="{{ $product->name }}" style="height: 200px; object-fit: cover;">
                                        @else
                                            <div class="bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                                <i class="bi bi-image text-muted fs-1"></i>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="card-body">
                                        <h6 class="card-title text-truncate" title="{{ $product->name }}">{{ $product->name }}</h6>
                                        <p class="card-text fw-bold text-primary mb-2">₦{{ number_format($product->amount, 2) }}</p>
                                        
                                        <div class="input-group input-group-sm mb-2">
                                            <span class="input-group-text">Qty</span>
                                            <input type="number" class="form-control product-qty" data-id="{{ $product->id }}" value="1" min="1">
                                        </div>
                                        
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100 add-to-pack-btn" data-id="{{ $product->id }}" data-store="{{ $store->id }}">
                                            <i class="bi bi-plus-lg me-1"></i> Add to Pack
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    
                    <div class="mt-4">
                        {{ $products->links() }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Custom Item Form -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 20px; z-index: 100;">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Custom Item</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Can't find what you're looking for? Add a custom item request.</p>
                    
                    <form id="customItemForm">
                        <input type="hidden" name="store_id" value="{{ $store->id }}">
                        
                        <div class="mb-3">
                            <label class="form-label">Item Name</label>
                            <input type="text" name="product_name" class="form-control" required placeholder="e.g. 5kg Rice">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Estimated Budget (₦)</label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input type="number" name="budgeted_amount" class="form-control" min="0" step="0.01" required placeholder="0.00">
                            </div>
                            <div class="form-text">Your estimated price for this item.</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            Add Custom Item
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="confirmModalLabel">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">
                Are you sure?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalAction">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index: 1060">
    <div id="liveToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">
                Item added to pack!
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const packDrawer = new bootstrap.Offcanvas(document.getElementById('packDrawer'));
        const toastEl = document.getElementById('liveToast');
        const toast = new bootstrap.Toast(toastEl);
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        
        // Initial Cart Load
        fetchCartData();

        // Floating Icon Click
        document.getElementById('floatingPackIcon').addEventListener('click', function() {
            packDrawer.show();
        });

        // Add Standard Item
        document.querySelectorAll('.add-to-pack-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const productId = this.dataset.id;
                const storeId = this.dataset.store;
                const qtyInput = document.querySelector(`.product-qty[data-id="${productId}"]`);
                const quantity = qtyInput ? qtyInput.value : 1;

                console.log('Adding product to pack:', { productId, storeId, quantity });

                addToPack({
                    product_id: productId,
                    store_id: storeId,
                    quantity: quantity
                });
            });
        });

        // Add Custom Item
        document.getElementById('customItemForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            console.log('Adding custom item to pack');

            fetch("{{ route('family-pack.cart.add-custom') }}", {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                if(data.success) {
                    showToast(data.message);
                    refreshCart(data.cart, data.count, data.subtotal);
                    this.reset();
                } else {
                    console.error('Error adding custom item:', data.message);
                    showToast(data.message || 'Error adding custom item', 'error');
                }
            })
            .catch(error => {
                console.error('Error adding custom item:', error);
                showToast(error.message || 'An error occurred while adding the item', 'error');
            });
        });

        // Helper: Add to Pack
        function addToPack(data) {
            fetch("{{ route('family-pack.cart.add') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                if(data.success) {
                    showToast(data.message);
                    fetchCartData(); // Fetch fresh data to get accurate subtotal
                } else {
                    console.error('Error adding to pack:', data.message);
                    showToast(data.message || 'Error adding item', 'error');
                }
            })
            .catch(error => {
                console.error('Error adding to pack:', error);
                showToast(error.message || 'An error occurred while adding the item', 'error');
            });
        }

        // Helper: Fetch Cart Data
        function fetchCartData() {
            fetch("{{ route('family-pack.cart.data') }}")
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to fetch cart data');
                }
                return response.json();
            })
            .then(data => {
                if(data.success) {
                    refreshCart(data.cart, data.count, data.subtotal);
                }
            })
            .catch(error => {
                console.error('Error fetching cart data:', error);
            });
        }

        // Helper: Refresh UI
        function refreshCart(cart, count, subtotal) {
            // Update Badge
            const badge = document.getElementById('packCountBadge');
            if(count > 0) {
                badge.innerText = count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }

            // Update Drawer List
            const list = document.getElementById('packItemsList');
            list.innerHTML = '';

            if (cart && cart.items && Object.keys(cart.items).length > 0) {
                Object.entries(cart.items).forEach(([key, item]) => {
                    const price = item.is_custom ? item.budgeted_amount : (item.unit_price * item.quantity);
                    const html = `
                        <div class="card mb-2 border-0 shadow-sm bg-light">
                            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                                <div class="overflow-hidden me-2">
                                    <h6 class="mb-0 text-truncate" title="${item.product_name}">${item.product_name}</h6>
                                    <small class="text-muted">
                                        Qty: ${item.quantity} ${item.is_custom ? '<span class="badge bg-info text-dark scale-50">Custom</span>' : ''}
                                    </small>
                                </div>
                                <div class="text-end" style="min-width: 80px;">
                                    <div class="fw-bold text-primary mb-1">₦${new Intl.NumberFormat().format(price)}</div>
                                    <button class="btn btn-link btn-sm p-0 text-danger" onclick="removeItem('${key}')">
                                        <i class="bi bi-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    list.insertAdjacentHTML('beforeend', html);
                });
            } else {
                list.innerHTML = `
                    <div class="text-center text-muted mt-5">
                        <i class="bi bi-basket3 fs-1 d-block mb-2"></i>
                        <p>Your pack is empty</p>
                    </div>
                `;
            }

            // Update Subtotal
            if(subtotal !== undefined) {
                document.getElementById('packSubtotal').innerText = '₦' + new Intl.NumberFormat().format(subtotal);
            }
        }

        // Helper: Show Toast
        function showToast(message, type = 'success') {
            const toastEl = document.getElementById('liveToast');
            toastEl.classList.remove('bg-success', 'bg-danger');
            toastEl.classList.add(type === 'error' ? 'bg-danger' : 'bg-success');
            document.getElementById('toastMessage').innerText = message;
            toast.show();
        }

        // Helper: Show Confirmation Modal
        function showConfirmation(message, onConfirm) {
            document.getElementById('confirmModalBody').innerText = message;
            const confirmBtn = document.getElementById('confirmModalAction');
            
            // Remove old event listeners by cloning
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            // Add new event listener
            newConfirmBtn.addEventListener('click', function() {
                confirmModal.hide();
                onConfirm();
            });
            
            confirmModal.show();
        }

        // Global: Remove Item
        window.removeItem = function(key) {
            showConfirmation('Remove this item from your pack?', function() {
                console.log('Removing item:', key);

                fetch("{{ route('family-pack.cart.remove') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ item_key: key })
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => Promise.reject(err));
                    }
                    return response.json();
                })
                .then(data => {
                    if(data.success) {
                        showToast(data.message);
                        refreshCart(data.cart, data.count, data.subtotal);
                    }
                })
                .catch(error => {
                    console.error('Error removing item:', error);
                    showToast(error.message || 'An error occurred while removing the item', 'error');
                });
            });
        };

        // Global: Clear Pack
        window.clearPack = function() {
            showConfirmation('Are you sure you want to clear your entire pack?', function() {
                console.log('Clearing pack');

                fetch("{{ route('family-pack.cart.clear') }}", {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => Promise.reject(err));
                    }
                    return response.json();
                })
                .then(data => {
                    if(data.success) {
                        showToast(data.message);
                        refreshCart({}, 0, 0);
                        packDrawer.hide();
                    }
                })
                .catch(error => {
                    console.error('Error clearing pack:', error);
                    showToast(error.message || 'An error occurred while clearing the pack', 'error');
                });
            });
        };
    });
</script>
@endpush
@endsection
