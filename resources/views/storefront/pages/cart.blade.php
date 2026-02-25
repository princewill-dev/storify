@extends('storefront.layout')
@section('title', 'Shopping Cart')

@section('content')

<!-- Custom CSS for this page to match the design reference -->
<style>
    .shopping-cart-area {
        background-color: #f8f9fa;
        min-height: 80vh;
    }
    .cart-header-title {
        font-family: 'Times New Roman', serif;
        font-weight: 700;
        font-size: 2.5rem;
        color: #1a1a1a;
        margin-bottom: 30px;
    }
    
    /* Desktop Layout (Default) */
    .cart-table-header {
        display: flex;
        align-items: center;
        border-bottom: 1px solid #eee;
        padding-bottom: 15px;
        margin-bottom: 20px;
        color: #444;
        font-weight: 600;
        font-size: 0.9rem;
        text-transform: uppercase;
    }
    .cart-col-product { flex: 3; display: flex; align-items: center; gap: 1rem; }
    .cart-col-size    { flex: 1; text-align: center; } 
    .cart-col-qty     { flex: 1.5; text-align: center; }
    .cart-col-price   { flex: 1; text-align: right; }
    .cart-col-remove  { flex: 0.5; text-align: right; }

    .cart-item-row {
        display: flex;
        align-items: center;
        background: #fff;
        border-radius: 12px;
        margin-bottom: 15px;
        padding: 20px;
        border-bottom: 1px solid #f0f0f0;
    }
    .cart-item-row:last-child { border-bottom: none; }
    
    .cart-thumb img,
    .cart-thumb video {
        width: 80px;
        height: 80px;
        object-fit: cover;
        border-radius: 8px;
        background: #f1f1f1;
    }
    .cart-details h5 {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 5px;
        color: #222;
    }
    .cart-details p {
        font-size: 0.85rem;
        color: #888;
        margin: 0;
    }

    /* Qty Selector - Pill Shape */
    .qty-selector-pill {
        display: inline-flex;
        align-items: center;
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 50px;
        padding: 5px 10px;
    }
    .qty-btn {
        background: none;
        border: none;
        font-size: 1.1rem;
        color: #333;
        cursor: pointer;
        padding: 0 10px;
    }
    .qty-input {
        width: 30px;
        text-align: center;
        border: none;
        font-weight: 600;
        color: #333;
    }
    .qty-input:focus { outline: none; }

    .item-price {
        font-weight: 600;
        color: #333;
    }
    .remove-btn {
        color: #ccc;
        cursor: pointer;
        transition: color 0.2s;
        font-size: 1.2rem;
    }
    .remove-btn:hover { color: #ff4d4d; }

    .continue-shopping-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 12px 25px;
        border-radius: 30px;
        border: 1px solid #ddd;
        background: #fff;
        color: #333;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s;
    }
    .continue-shopping-btn:hover {
        border-color: #333;
        background: #333;
        color: #fff;
    }

    /* Right Column: Dark Panel */
    .checkout-panel {
        background-color: #1e2a38; 
        color: #fff;
        border-radius: 20px;
        padding: 40px;
        height: 100%;
        min-height: 500px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .panel-title {
        font-family: 'Times New Roman', serif;
        font-size: 2rem;
        margin-bottom: 30px;
        color: #fff;
    }
    
    .summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 15px;
        font-size: 1rem;
        color: #aab2bd;
    }
    .summary-row.total {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid rgba(255,255,255,0.1);
        color: #fff;
        font-size: 1.5rem;
        font-weight: 700;
    }
    .checkout-btn {
        display: block;
        width: 100%;
        background-color: #3b82f6; 
        color: #fff;
        text-align: center;
        padding: 18px 0;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 600;
        text-decoration: none;
        transition: background-color 0.3s;
        border: none;
    }
    .checkout-btn:hover {
        background-color: #2563eb;
        color: #fff;
    }
    .checkout-btn:disabled,
    .checkout-btn:disabled:hover {
        background-color: #3b82f6;
        cursor: not-allowed !important;
        pointer-events: auto;
    }

    /* Mobile / Tablet Optimization */
    @media (max-width: 991px) {
        .checkout-panel { margin-top: 40px; min-height: auto; }
        .cart-header-title { font-size: 2rem; }
    }

    /* Specific Mobile Card Layout */
    @media (max-width: 767px) {
        .cart-header-title { font-size: 1.8rem; margin-bottom: 20px; }
        .cart-col-size { display: none; }
        
        .cart-item-row {
            display: grid;
            grid-template-columns: 85px 1fr auto; 
            grid-template-areas: 
                "img details remove" 
                "img actions price";
            gap: 10px;
            padding: 15px;
            align-items: start;
        }

        /* Unwrap the product column so logic works with grid */
        .cart-col-product { display: contents; }

        .cart-thumb {
            grid-area: img;
            width: 80px; 
            height: 80px;
        }
        
        .cart-details {
            grid-area: details;
            align-self: center;
        }
        .cart-details h5 { font-size: 0.95rem; margin-bottom: 2px; }
        
        .cart-col-remove {
            grid-area: remove;
            text-align: right;
        }
        
        .cart-col-qty {
            grid-area: actions;
            align-self: center;
            text-align: left;
        }
        
        .cart-col-price {
            grid-area: price;
            text-align: right;
            align-self: center;
            font-size: 0.95rem;
        }
    }
</style>

<section class="shopping-cart-area pt-80 pb-80">
    <div class="container">
        
        <div class="row">
            <div class="col-12 mb-4">
                @if(!$hasPaymentMethods)
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-triangle me-3 fa-2x"></i>
                        <div>
                            <h5 class="alert-heading mb-1">Store Not Accepting Orders Yet</h5>
                            <p class="mb-0">This store is currently not receiving orders. Please check back later!</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="row">
            <!-- Left Side: Cart Items -->
            <div class="col-lg-8 pr-lg-5">
                <h1 class="cart-header-title">Shopping Cart.</h1>

                <!-- Hide header on mobile -->
                <div class="cart-table-header d-none d-md-flex">
                    <div class="cart-col-product" style="display: block;">Product</div>
                    <div class="cart-col-size">Size</div>
                    <div class="cart-col-qty">Quantity</div>
                    <div class="cart-col-price">Total Price</div>
                    <div class="cart-col-remove"></div>
                </div>

                <div id="cartPageList">
                    <!-- JS will inject .cart-item-row elements here -->
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>

                <div class="mt-5">
                    <a href="{{ store_url($store->slug ?? 'store') }}" class="continue-shopping-btn">
                        <i class="fas fa-arrow-left"></i> Continue Shopping
                    </a>
                </div>

            </div>

            <!-- Right Side: Checkout / Summary Panel -->
            <div class="col-lg-4">
                <div class="checkout-panel">
                    <div>
                        <h2 class="panel-title">Order Summary.</h2>

                        <hr style="border-color: rgba(255,255,255,0.1);">

                        <div class="summary-body mt-4">
                                                      
                           <!-- Delivery Route Selector -->
                           <div class="summary-row" style="flex-direction: column; gap: 10px; align-items: stretch; margin-bottom: 20px;">
                                <div class="d-flex justify-content-between">
                                    <span>Delivery:</span>
                                    <span id="deliveryFeeDisplay">select delivery location</span>
                                </div>
                                
                                <select id="deliveryState" class="form-select form-select-sm" style="background: #2b3948; color: #fff; border-color: rgba(255,255,255,0.1);">
                                    <option value="">Select State</option>
                                    @foreach($states as $state)
                                        <option value="{{ $state }}">{{ $state }}</option>
                                    @endforeach
                                </select>
                                
                                <div id="areaSpinner" class="text-center py-2" style="display: none;">
                                    <div class="spinner-border spinner-border-sm text-light" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>

                                <select id="deliveryArea" class="form-select form-select-sm" style="background: #2b3948; color: #fff; border-color: rgba(255,255,255,0.1); display: none;">
                                    <option value="">Select Area</option>
                                </select>
                           </div>

                           <div class="summary-row">
                                <span>Subtotal:</span>
                                <span id="cartPageSubtotal">0.00</span>
                           </div>
                           
                           <div class="summary-row total">
                                <span>Total:</span>
                                <span id="cartPageTotal">0.00</span>
                           </div>
                        </div>
                    </div>

                    <div class="mt-4" id="checkoutBtnContainer">
                        <button type="button" id="checkoutBtn" class="checkout-btn" data-ready="false" style="opacity: 0.5;">
                            Check Out.
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Error Modal -->
<div class="modal fade" id="cartErrorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-danger">Notice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <p id="cartErrorModalMsg" class="mb-0 fs-5 text-dark">Something went wrong.</p>
      </div>
      <div class="modal-footer border-0 justify-content-center pt-0">
        <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>

    const cartAreasByState = @json($areasByState);
    const proceedUrl = "{{ request()->routeIs('local.*') ? route('local.store.cart.proceed', ['store_subdomain' => $store->slug]) : route('home.store.cart.proceed', ['store_subdomain' => $store->slug]) }}";
    const hasPaymentMethods = {{ $hasPaymentMethods ? 'true' : 'false' }};

    document.addEventListener('DOMContentLoaded', function() {
        // ... existing code ...
        
        const stateSelect = document.getElementById('deliveryState');
        const areaSelect = document.getElementById('deliveryArea');
        const feeDisplay = document.getElementById('deliveryFeeDisplay');
        const totalEl = document.getElementById('cartPageTotal');
        const checkoutBtn = document.getElementById('checkoutBtn');
        const checkoutBtnContainer = document.getElementById('checkoutBtnContainer');
        const spinner = document.getElementById('areaSpinner');
        
        let currentSubtotal = 0;
        let shippingFee = 0;
        let deliveryRouteSelected = false;

        // Helper to update button visibility
        function updateCheckoutButton() {
            if (!hasPaymentMethods || !deliveryRouteSelected) {
                checkoutBtn.dataset.ready = "false";
                checkoutBtn.style.opacity = '0.5';
            } else {
                checkoutBtn.dataset.ready = "true";
                checkoutBtn.style.opacity = '1';
            }
        }

        // Helper to update total
        function updateCartTotal() {
            const total = currentSubtotal + shippingFee;
            const fmt = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' });
            totalEl.innerText = fmt.format(total);
        }
        
        // Helper to show error modal
        function showModalError(msg) {
            const el = document.getElementById('cartErrorModalMsg');
            const modalEl = document.getElementById('cartErrorModal');
            if(el && modalEl) {
                el.innerText = msg;
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                alert(msg); // Fallback
            }
        }

        // Checkout button click handler
        checkoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (this.dataset.ready === "false" || !deliveryRouteSelected) {
                showModalError("Please select your state and delivery area before checking out.");
                return;
            }
            
            const routeId = areaSelect.value;
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            // Show loading state
            const originalText = this.innerText;
            this.innerText = 'Processing...';
            this.style.opacity = '0.7';
            this.style.pointerEvents = 'none';

            fetch(proceedUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    delivery_route_id: routeId || null
                })
            })
            .then(async response => {
                const isJson = response.headers.get('content-type')?.includes('application/json');
                const data = isJson ? await response.json() : null;

                if (!response.ok) {
                    // Check for validation errors
                    if (data && data.errors) {
                         const firstField = Object.keys(data.errors)[0];
                         const firstError = data.errors[firstField][0];
                         throw new Error(firstError);
                    }
                    
                    const error = (data && data.message) || response.statusText;
                    throw new Error(error);
                }
                return data;
            })
            .then(data => {
                if (data && data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else if (data && data.error) {
                    showModalError(data.error);
                    // Reset button
                    this.innerText = originalText;
                    this.style.opacity = '1';
                    this.style.pointerEvents = 'auto';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                
                // Fallback for generic "The given data was invalid" message without specific field errors showing up
                let msg = error.message;
                if (msg === 'The given data was invalid.') {
                     msg = 'Please check your input selections.';
                }
                
                showModalError(msg || 'Something went wrong. Please check your connection and try again.');
                // Reset button
                this.innerText = originalText;
                this.style.opacity = '1';
                this.style.pointerEvents = 'auto';
            });
        });

        // Capture subtotal updates from renderCartPage
        const originalRenderCartPage = renderCartPage;
        window.renderCartPage = function(cart) {
            originalRenderCartPage(cart); // call original
            currentSubtotal = cart.subtotal / 100;
            updateCartTotal(); // Recalculate with current shipping fee
        };

        // State change handler
        stateSelect.addEventListener('change', function() {
            const state = this.value;
            areaSelect.innerHTML = '<option value="">Select Area</option>';
            areaSelect.style.display = 'none';
            
            if (state) {
                // Show spinner
                spinner.style.display = 'block';
                
                // Small timeout to simulate loading/allow UI update
                setTimeout(() => {
                    spinner.style.display = 'none';
                    
                    if (cartAreasByState[state]) {
                        cartAreasByState[state].forEach(route => {
                            const opt = document.createElement('option');
                            opt.value = route.id;
                            opt.text = route.area ? `${route.area} (₦${(route.fee/100).toLocaleString()})` : `All Areas (₦${(route.fee/100).toLocaleString()})`;
                            opt.dataset.fee = route.fee;
                            areaSelect.appendChild(opt);
                        });
                        areaSelect.style.display = 'block';
                    }
                }, 500); // 500ms delay for visual feedback
            } else {
                spinner.style.display = 'none';
            }
            
            // Reset fee when state changes
            shippingFee = 0;
            deliveryRouteSelected = false;
            feeDisplay.innerText = 'Please choose area';
            updateCartTotal();
            updateCheckoutButton();
        });

        // Area change handler
        areaSelect.addEventListener('change', function() {
            const selectedOpt = this.options[this.selectedIndex];
            if (selectedOpt.value) {
                const feeKobo = parseInt(selectedOpt.dataset.fee || 0);
                shippingFee = feeKobo / 100;
                deliveryRouteSelected = true;
                
                const fmt = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' });
                feeDisplay.innerText = fmt.format(shippingFee);
            } else {
                shippingFee = 0;
                deliveryRouteSelected = false;
                feeDisplay.innerText = 'Please choose area';
            }
            updateCartTotal();
            updateCheckoutButton();
        });
    });


    document.addEventListener('DOMContentLoaded', function() {
        const originalRender = StorefrontCart.renderCart;
        
        StorefrontCart.renderCart = function(cart) {
            originalRender.call(StorefrontCart, cart); // Update mini cart
            
            // Check if we are on the cart page by looking for the ID
            if (document.getElementById('cartPageList')) {
                renderCartPage(cart);
            }
        };

        // Trigger initial load
        StorefrontCart.refreshCart();
    });

    function renderCartPage(cart) {
        const list = document.getElementById('cartPageList');
        const subtotalEl = document.getElementById('cartPageSubtotal');
        const totalEl = document.getElementById('cartPageTotal');
        
        list.innerHTML = '';
        const fmt = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' });
        
        subtotalEl.innerText = fmt.format(cart.subtotal / 100);
        totalEl.innerText = fmt.format(cart.total / 100);

        if (cart.items && cart.items.length > 0) {
            cart.items.forEach(function(item) {
                let mediaHtml = '';
                let imageSrc = item.image ? `/storage/${item.image}` : `{{ asset('storefront/assets/img/product/product-1.jpg') }}`;
                
                // Check if it's a video
                if (item.image) {
                    const ext = item.image.split('.').pop().toLowerCase();
                    const isVideo = ['mp4', 'webm', 'mov', 'avi', 'mpeg'].includes(ext);
                    
                    if (isVideo) {
                        const mimeType = ext === 'mov' ? 'quicktime' : ext;
                        mediaHtml = `
                            <div style="position: relative; width: 80px; height: 80px;">
                                <video style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;" muted>
                                    <source src="${imageSrc}" type="video/${mimeType}">
                                </video>
                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;">
                                    <i class="fas fa-play-circle text-white" style="font-size: 1.5rem; opacity: 0.9; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));"></i>
                                </div>
                            </div>
                        `;
                    } else {
                        mediaHtml = `<img src="${imageSrc}" alt="${item.name}">`;
                    }
                } else {
                    mediaHtml = `<img src="${imageSrc}" alt="${item.name}">`;
                }
                
                // Url logic
                let productUrl = '#';
                if (item.slug && item.code) {
                     productUrl = `{{ store_url($store->slug ?? 'store', 'products/') }}${item.slug}-${item.code}`;
                }

                const row = document.createElement('div');
                row.className = 'cart-item-row';
                
                const sizeDisplay = '-'; 

                // Updated HTML structure without inline d-flex classes that might conflict with mobile grid
                row.innerHTML = `
                    <div class="cart-col-product">
                        <div class="cart-thumb">
                            <a href="${productUrl}">
                                ${mediaHtml}
                            </a>
                        </div>
                        <div class="cart-details">
                            <h5><a href="${productUrl}" class="text-dark text-decoration-none">${item.name}</a></h5>
                            <p class="text-muted">Code: ${item.code || 'N/A'}</p>
                        </div>
                    </div>
                    
                    <div class="cart-col-size d-none d-md-block">
                        <span class="badge bg-light text-dark border">${sizeDisplay}</span>
                    </div>

                    <div class="cart-col-qty">
                         <div class="qty-selector-pill">
                            <button class="qty-btn" onclick="StorefrontCart.updateItemQty('${item.id}', ${item.qty - 1})">−</button>
                            <input class="qty-input" type="text" value="${item.qty}" readonly>
                            <button class="qty-btn" onclick="StorefrontCart.updateItemQty('${item.id}', ${item.qty + 1})">+</button>
                         </div>
                    </div>

                    <div class="cart-col-price">
                        <span class="item-price">${fmt.format(item.line_subtotal / 100)}</span>
                    </div>

                    <div class="cart-col-remove">
                        <i class="fas fa-times remove-btn" onclick="StorefrontCart.removeItem('${item.id}')" title="Remove Item"></i>
                    </div>
                `;
                list.appendChild(row);
            });
        } else {
             list.innerHTML = `
                <div class="text-center py-5">
                    <div class="mb-3"><i class="fal fa-shopping-cart fa-3x text-muted"></i></div>
                    <h3>Your cart is empty</h3>
                    <p class="text-muted mb-4">Looks like you haven't added anything yet.</p>
                    <a href="{{ store_url($store->slug ?? 'store') }}" class="m-btn m-btn-border-2">Start Shopping</a>
                </div>
             `;
        }
    }
</script>
@endsection
