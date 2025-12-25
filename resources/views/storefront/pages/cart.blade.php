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
    
    .cart-thumb img {
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
                <!-- Header optional -->
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
                        
                        <div class="mb-4">
                            <span class="d-block mb-2 text-muted" style="font-size:0.9rem;">Accepted Payment Methods:</span>
                            <div class="d-flex gap-2">
                                <i class="fab fa-cc-mastercard fa-2x text-white-50"></i>
                                <i class="fab fa-cc-visa fa-2x text-white-50"></i>
                                <i class="fab fa-cc-paypal fa-2x text-white-50"></i>
                            </div>
                        </div>

                        <hr style="border-color: rgba(255,255,255,0.1);">

                        <div class="summary-body mt-4">
                           <div class="summary-row">
                                <span>Subtotal:</span>
                                <span id="cartPageSubtotal">0.00</span>
                           </div>
                           <div class="summary-row">
                                <span>Shipping:</span>
                                <span>Calculated at checkout</span>
                           </div>
                           
                           <div class="summary-row total">
                                <span>Total:</span>
                                <span id="cartPageTotal">0.00</span>
                           </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('checkout.index', ['store_slug' => $store->slug]) }}" class="checkout-btn">Check Out.</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
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
                let imageSrc = item.image ? `/storage/${item.image}` : `{{ asset('storefront/assets/img/product/product-1.jpg') }}`;
                
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
                                <img src="${imageSrc}" alt="${item.name}">
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
