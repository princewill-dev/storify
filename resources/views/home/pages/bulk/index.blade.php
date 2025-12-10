@extends('home.layout')
@section('title', $store->name.' - Bulk Buy')

@section('content')

<div class="page-content">
    <!--banner-->
    <div class="dz-bnr-inr style-1" style="background-image:url({{asset('home/images/background/bg-shape.jpg')}});">
        <div class="container">
            <div class="dz-bnr-inr-entry">
                <h1>Bulk Purchase - {{ $store->name }}</h1>
                <p class="text-muted">Special pricing for bulk orders. Great for companies and businesses!</p>
                <nav aria-label="breadcrumb" class="breadcrumb-row">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('home.index') }}"> Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('home.store.products.index', $store->slug) }}">{{ $store->name }}</a></li>
                        <li class="breadcrumb-item">Bulk Buy</li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <section class="content-inner-1 pt-4 z-index-unset">
        <div class="container">
            
            <!-- Info Banner -->
            <!-- <div class="alert alert-info mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fa fa-info-circle fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1">Bulk Ordering Information</h5>
                        <p class="mb-0 small">These products are available for bulk purchase with special pricing. Select products, add them to your bulk cart, then submit your request for admin review before payment.</p>
                    </div>
                </div>
            </div> -->

            @if($products->count() === 0)
                <div class="alert alert-warning">
                    <h5>No Bulk Products Available</h5>
                    <p class="mb-0">There are currently no products available for bulk purchase from this store. Please check back later or <a href="{{ route('home.store.products.index', $store->slug) }}">browse all products</a>.</p>
                </div>
            @else
                <!-- Products Grid: 3 columns -->
                <div class="row g-4">
                    @foreach($products as $product)
                        <div class="col-lg-4 col-md-6">
                            <div class="card h-100 shadow-sm border-0">
                                <!-- Product Image -->
                                <div class="position-relative">
                                    @php($firstImage = optional($product->images->first())->path)
                                    @if($firstImage)
                                        <img src="{{ asset('storage/'.$firstImage) }}" class="card-img-top" alt="{{ $product->name }}" style="height: 250px; object-fit: contain; padding: 15px;">
                                    @else
                                        <img src="{{ asset('home/images/no-image.jpg') }}" class="card-img-top" alt="{{ $product->name }}" style="height: 250px; object-fit: contain; padding: 15px;">
                                    @endif
                                    
                                    <!-- Bulk Badge -->
                                    <div class="position-absolute top-0 end-0 m-2">
                                        <span class="badge bg-success">Bulk Available</span>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <h5 class="card-title">
                                        <a href="{{ route('home.products.show', ['store_slug' => $product->store->slug, 'slug' => $product->slug, 'code' => $product->product_code]) }}" class="text-decoration-none text-dark">
                                            {{ $product->name }}
                                        </a>
                                    </h5>
                                    
                                    <!-- Regular Price -->
                                    <div class="mb-2">
                                        <span class="text-muted small">Regular Price:</span><br>
                                        <span class="fw-bold">{{ $product->display_price }}</span>
                                    </div>

                                    <hr>

                                    <!-- Bulk Pricing Info -->
                                    <div class="bg-light p-3 rounded mb-3">
                                        <h6 class="text-success mb-2"><i class="fa fa-box"></i> Bulk Pricing</h6>
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Min. Quantity</small>
                                                <strong class="text-primary">{{ number_format($product->bulk_quantity) }} units</strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Bulk Price</small>
                                                <strong class="text-success">{{ $product->currency->symbol }}{{ number_format($product->bulk_price, 2) }}</strong>
                                                <small class="d-block text-muted">for {{ $product->bulk_quantity }} units</small>
                                            </div>
                                        </div>

                                        @if($product->bulk_savings_percent > 0)
                                            <div class="mt-2 pt-2 border-top">
                                                <span class="badge bg-warning text-dark">Save {{ number_format($product->bulk_savings_percent, 1) }}% per unit!</span>
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Add to Bulk Cart Button -->
                                    <button class="btn btn-primary w-100" data-add-to-bulk-cart data-product-id="{{ $product->id }}" data-bulk-qty="{{ $product->bulk_quantity }}" data-bulk-price="{{ $product->bulk_price }}">
                                        <i class="fa fa-cart-plus"></i> Add to Bulk Cart
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-4">
                    {{ $products->links() }}
                </div>
            @endif

            <!-- Custom Products Section -->
            <div class="card mt-5 border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fa fa-plus-circle"></i> Request Custom Products</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Can't find what you're looking for? Add your custom product requirements below.</p>
                    
                    <div id="custom-products-container">
                        <!-- Custom product rows will be added here -->
                    </div>

                    <button type="button" class="btn btn-outline-primary" id="add-custom-product">
                        <i class="fa fa-plus"></i> Add Custom Product
                    </button>
                </div>
            </div>

        </div>
    </section>

    <!-- Floating Bulk Cart Button -->
    <button class="btn btn-success btn-lg rounded-circle position-fixed" id="bulk-cart-btn" 
            style="bottom: 30px; right: 30px; width: 60px; height: 60px; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.3);" 
            data-bs-toggle="modal" data-bs-target="#bulkCartModal">
        <i class="fa fa-shopping-cart"></i>
        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" id="cart-count" style="display: none;">0</span>
    </button>

    <!-- Bulk Cart Modal -->
    <div class="modal fade" id="bulkCartModal" tabindex="-1" aria-labelledby="bulkCartModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="bulkCartModalLabel">
                        <i class="fa fa-shopping-cart"></i> Bulk Cart
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="cart-items-container">
                        <div class="text-center text-muted py-5">
                            <i class="fa fa-shopping-cart fa-3x mb-3"></i>
                            <p>Your bulk cart is empty</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                    <button type="button" class="btn btn-success" id="proceed-to-checkout-modal">
                        <i class="fa fa-arrow-right"></i> Proceed to Checkout
                    </button>
                </div>
            </div>
        </div>
    </div>


<script>
let customProductCounter = 0;

// Update cart display
async function updateCartDisplay() {
    try {
        const response = await fetch('{{ route("bulk.cart.get") }}');
        const data = await response.json();
        
        if (data.success) {
            const cart = data.cart;
            const totalItems = Object.keys(cart.items || {}).length + (cart.custom_items || []).length;
            
            // Update cart count badge
            const cartCount = document.getElementById('cart-count');
            if (totalItems > 0) {
                cartCount.textContent = totalItems;
                cartCount.style.display = 'block';
            } else {
                cartCount.style.display = 'none';
            }
            
            // Update modal content
            const container = document.getElementById('cart-items-container');
            
            // Update Custom Products Form (Sync with Cart)
            // Only update if we are not currently editing (simple check: active element is not an input in the container)
            const customContainer = document.getElementById('custom-products-container');
            const activeElement = document.activeElement;
            const isEditingCustom = customContainer.contains(activeElement);

            if (!isEditingCustom) {
                customContainer.innerHTML = '';
                customProductCounter = 0;

                if (cart.custom_items && cart.custom_items.length > 0) {
                    cart.custom_items.forEach(item => {
                        customProductCounter++;
                        const row = document.createElement('div');
                        row.className = 'custom-product-row row g-3 mb-3 p-3 border rounded';
                        row.innerHTML = `
                            <div class="col-md-4">
                                <label class="form-label small">Product Name/Description</label>
                                <input type="text" class="form-control" name="custom_products[${customProductCounter}][name]" value="${item.name}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Quantity</label>
                                <input type="number" class="form-control" name="custom_products[${customProductCounter}][quantity]" value="${item.quantity}" min="1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Budgeted Amount (₦)</label>
                                <input type="number" class="form-control" name="custom_products[${customProductCounter}][budget]" value="${item.budgeted_amount}" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-danger remove-custom-product w-100">
                                    <i class="fa fa-trash"></i> Remove
                                </button>
                            </div>
                        `;
                        customContainer.appendChild(row);
                    });
                }
            }
            
            if (totalItems === 0) {
                container.innerHTML = `
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-shopping-cart fa-3x mb-3"></i>
                        <p>Your bulk cart is empty</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            let subtotal = 0;
            
            // Display products
            if (cart.items && Object.keys(cart.items).length > 0) {
                html += '<h6 class="mb-3">Products</h6>';
                for (const [id, item] of Object.entries(cart.items)) {
                    subtotal += parseFloat(item.subtotal);
                    html += `
                        <div class="card mb-2">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">${item.name}</h6>
                                        <small class="text-muted">Code: ${item.product_code}</small><br>
                                        <small class="text-muted">Quantity: ${item.quantity.toLocaleString()} units</small><br>
                                        <small class="text-success fw-bold">${item.currency_symbol}${parseFloat(item.subtotal).toLocaleString('en-US', {minimumFractionDigits: 2})}</small>
                                    </div>
                                    <button class="btn btn-sm btn-danger" onclick="removeFromCart(${id})">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
            
            // Display custom items
            if (cart.custom_items && cart.custom_items.length > 0) {
                html += '<h6 class="mb-3 mt-4">Custom Product Requests</h6>';
                cart.custom_items.forEach((item, index) => {
                    subtotal += parseFloat(item.budgeted_amount);
                    html += `
                        <div class="card mb-2 border-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">${item.name}</h6>
                                        <small class="text-muted">Quantity: ${item.quantity.toLocaleString()}</small><br>
                                        <small class="text-success fw-bold">Budgeted: ₦${parseFloat(item.budgeted_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</small>
                                    </div>
                                    <button class="btn btn-sm btn-danger" onclick="removeCustomFromCart(${index})">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            // Add subtotal
            html += `
                <div class="card bg-light mt-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Estimated Total:</h5>
                            <h4 class="mb-0 text-success">₦${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2})}</h4>
                        </div>
                        <small class="text-muted">Final pricing will be confirmed after admin review</small>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }
    } catch (error) {
        console.error('Error updating cart display:', error);
    }
}

// Remove item from cart
async function removeFromCart(productId) {
    if (!confirm('Remove this item from cart?')) return;
    
    try {
        const response = await fetch(`/bulk/cart/${productId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            updateCartDisplay();
        }
    } catch (error) {
        console.error('Error removing from cart:', error);
    }
}

// Remove custom item from cart
async function removeCustomFromCart(index) {
    if (!confirm('Remove this custom item from cart?')) return;
    
    try {
        const response = await fetch(`/bulk/cart/custom/${index}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            updateCartDisplay();
        }
    } catch (error) {
        console.error('Error removing custom item:', error);
    }
}

// Add custom product row
document.getElementById('add-custom-product').addEventListener('click', function() {
    if (customProductCounter >= 10) {
        alert('Maximum 10 custom products allowed');
        return;
    }

    customProductCounter++;
    const container = document.getElementById('custom-products-container');
    const row = document.createElement('div');
    row.className = 'custom-product-row row g-3 mb-3 p-3 border rounded';
    row.innerHTML = `
        <div class="col-md-4">
            <label class="form-label small">Product Name/Description</label>
            <input type="text" class="form-control" name="custom_products[${customProductCounter}][name]" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Quantity</label>
            <input type="number" class="form-control" name="custom_products[${customProductCounter}][quantity]" min="1" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Budgeted Amount (₦)</label>
            <input type="number" class="form-control" name="custom_products[${customProductCounter}][budget]" min="0" step="0.01" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-danger remove-custom-product w-100">
                <i class="fa fa-trash"></i> Remove
            </button>
        </div>
    `;
    container.appendChild(row);
});

// Remove custom product row
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-custom-product') || e.target.parentElement.classList.contains('remove-custom-product')) {
        const row = e.target.closest('.custom-product-row');
        row.remove();
        // Don't decrement counter to avoid ID collisions
    }
});


// Add to bulk cart functionality
document.addEventListener('click', async function(e) {
    if (e.target.hasAttribute('data-add-to-bulk-cart') || e.target.closest('[data-add-to-bulk-cart]')) {
        const btn = e.target.hasAttribute('data-add-to-bulk-cart') ? e.target : e.target.closest('[data-add-to-bulk-cart]');
        const productId = btn.dataset.productId;
        const bulkQty = parseInt(btn.dataset.bulkQty);
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Adding...';
        
       try {
            const response = await fetch('{{ route("bulk.cart.add") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: bulkQty
                })
            });

            const data = await response.json();

            if (data.success) {
                btn.innerHTML = '<i class="fa fa-check"></i> Added!';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                
                // Update cart display
                updateCartDisplay();
                
                setTimeout(() => {
                    btn.innerHTML = '<i class="fa fa-cart-plus"></i> Add to Bulk Cart';
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-primary');
                    btn.disabled = false;
                }, 2000);
            } else {
                alert(data.message || 'Failed to add to cart');
                btn.innerHTML = '<i class="fa fa-cart-plus"></i> Add to Bulk Cart';
                btn.disabled = false;
            }
        } catch (error) {
            console.error('Error adding to cart:', error);
            alert('An error occurred. Please try again.');
            btn.innerHTML = '<i class="fa fa-cart-plus"></i> Add to Bulk Cart';
            btn.disabled = false;
        }
    }
});

// Handle custom products - save to bulk cart when user navigates to checkout
document.addEventListener('DOMContentLoaded', function() {
    // Load cart on page load
    updateCartDisplay();
    
    // Add "Proceed to Checkout" button
    const customProductsCard = document.querySelector('.card.mt-5');
    if (customProductsCard) {
        const checkoutBtn = document.createElement('button');
        checkoutBtn.type = 'button';
        checkoutBtn.className = 'btn btn-success btn-lg w-100 mt-3';
        checkoutBtn.innerHTML = '<i class="fa fa-shopping-cart"></i> Proceed to Checkout';
        checkoutBtn.onclick = proceedToCheckout;
        customProductsCard.querySelector('.card-body').appendChild(checkoutBtn);
    }
    
    // Modal checkout button
    document.getElementById('proceed-to-checkout-modal').onclick = proceedToCheckout;
});

async function proceedToCheckout() {
    const customRows = document.querySelectorAll('.custom-product-row');
    const customItems = [];
    
    // Gather all custom products
    for (const row of customRows) {
        const nameInput = row.querySelector('input[name*="[name]"]');
        const qtyInput = row.querySelector('input[name*="[quantity]"]');
        const budgetInput = row.querySelector('input[name*="[budget]"]');
        
        if (nameInput && qtyInput && budgetInput && nameInput.value && qtyInput.value && budgetInput.value) {
            customItems.push({
                name: nameInput.value,
                quantity: parseInt(qtyInput.value),
                budgeted_amount: parseFloat(budgetInput.value)
            });
        }
    }
    
    // Sync with server (even if empty, to clear if user removed all)
    try {
        await fetch('{{ route("bulk.cart.custom.sync") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                custom_items: customItems
            })
        });
        
        // Redirect to checkout
        // Redirect to checkout
        window.location.href = '{{ route("bulk.checkout", $store->slug) }}';
        
    } catch (error) {
        console.error('Error syncing custom products:', error);
        alert('Failed to save custom products. Please try again.');
    }
}

// Remove item from cart
async function removeFromCart(productId) {
    if (!confirm('Remove this item from cart?')) return;
    
    try {
        const response = await fetch(`/bulk/cart/${productId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            updateCartDisplay();
        }
    } catch (error) {
        console.error('Error removing from cart:', error);
    }
}

// Remove custom item from cart
async function removeCustomFromCart(index) {
    if (!confirm('Remove this custom item from cart?')) return;
    
    try {
        const response = await fetch(`/bulk/cart/custom/${index}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            updateCartDisplay();
        }
    } catch (error) {
        console.error('Error removing custom item:', error);
    }
}
</script>

@endsection
