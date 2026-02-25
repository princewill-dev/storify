<script>
    const StorefrontCart = {
        storeSlug: "{{ $store->slug ?? 'store' }}",
        cartItemCount: 0,
        pendingBuyNow: null,
        
        init: function() {
            this.refreshCart();
            this.bindEvents();
        },

        bindEvents: function() {
            // Index page Add to Cart buttons
            $(document).on('click', '.add-to-cart-btn-index', function(e) {
                e.preventDefault();
                const btn = $(this);
                if (btn.hasClass('stock-maxed')) return; // Guard: already at limit
                const productId = btn.data('product-id');
                StorefrontCart.addToCart(productId, 1, null, btn);
            });

            // Product Details Add to Cart button
            $('#addToCartDetails').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                if (btn.hasClass('stock-maxed')) return; // Guard: already at limit
                const productId = btn.data('product-id');
                const hasVariants = btn.data('has-variants') === 'true';
                const qty = $('#quantity').val() || 1;
                
                let variantKey = null;
                // Only use size as variant key for actual variant products,
                // non-variant products show a fixed size display button but it's not a real variant
                if (hasVariants) {
                    const sizeBtn = $('.size-btn.active');
                    const size = sizeBtn.length ? sizeBtn.text().trim() : '';
                    if (size) variantKey = size + '||';
                }
                
                StorefrontCart.addToCart(productId, qty, variantKey, btn);
            });

            // Buy Now Button
            $('#buyNowBtn').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const productId = btn.data('product-id');
                const hasVariants = btn.data('has-variants') === 'true';
                const qty = $('#quantity').val() || 1;
                
                let variantKey = null;
                if (hasVariants) {
                    const sizeBtn = $('.size-btn.active');
                    const size = sizeBtn.length ? sizeBtn.text().trim() : '';
                    if (size) variantKey = size + '||';
                }
                
                if (StorefrontCart.cartItemCount > 0) {
                    StorefrontCart.pendingBuyNow = { productId, qty, variantKey, btn };
                    $('#buyNowConflictModal').modal('show');
                } else {
                    StorefrontCart.processBuyNow(productId, qty, variantKey, btn);
                }
            });

            // Modal Button: Checkout ONLY this item
            $('#btnCheckoutSingleItem').on('click', function() {
                const data = StorefrontCart.pendingBuyNow;
                if (!data) return;
                
                const btn = $(this);
                const originalHtml = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);
                
                StorefrontCart.processBuyNow(data.productId, data.qty, data.variantKey, data.btn);
            });

            // Modal Button: Add to cart & Checkout ALL
            $('#btnCheckoutAllItems').on('click', function() {
                const data = StorefrontCart.pendingBuyNow;
                if (!data) return;
                
                const btn = $(this);
                const originalHtml = btn.html();
                btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);
                
                // Add to standard cart, and if successful, redirect to cart page so they can proceed
                StorefrontCart.addToCart(data.productId, data.qty, data.variantKey, btn, function(response) {
                    window.location.href = "{{ route('home.store.cart', ['store_subdomain' => $store->slug ?? '']) }}";
                });
            });
        },

        addToCart: function(productId, qty, variantKey, btn, successCallback) {
            const originalHtml = btn.data('original-html') || btn.html(); 
            if (!btn.data('original-html')) btn.data('original-html', originalHtml);
            
            const isIconBtn = btn.hasClass('m-btn');
            
            if (isIconBtn) {
                 btn.find('span').text('...');
            } else {
                 btn.text('...');
            }
            btn.prop('disabled', true);

            $.ajax({
                url: '/cart/add',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    product_id: productId,
                    qty: qty,
                    variant_key: variantKey,
                    store_slug: this.storeSlug 
                },
                success: function(response) {
                    if (isIconBtn) {
                         btn.find('span').text('✔');
                    } else {
                         btn.text('✔');
                    }
                    
                    StorefrontCart.updateCartCount(response.item_count);
                    StorefrontCart.renderCart(response);

                    // Check if we've now reached the stock limit
                    const maxStock = response.max_stock;
                    const cartQty  = response.cart_qty;
                    if (maxStock !== null && maxStock !== undefined && cartQty !== undefined && cartQty >= maxStock) {
                        StorefrontCart.markBtnMaxed(btn, isIconBtn);
                        return;
                    }
                    
                    if (successCallback) {
                        successCallback(response);
                        return; // Skip reverting button if redirecting
                    }

                    // Revert button after delay
                    setTimeout(function() {
                        btn.html(originalHtml);
                        btn.prop('disabled', false);
                    }, 2000);
                },
                error: function(xhr) {
                    console.error('Add to cart failed', xhr);
                    let msg = 'Failed';
                    let isStockError = false;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                        isStockError = (xhr.status === 422 && xhr.responseJSON.max_stock !== undefined);
                    }

                    if (isStockError) {
                        StorefrontCart.showStockToast(msg);
                        StorefrontCart.markBtnMaxed(btn, isIconBtn);
                    } else {
                        if (isIconBtn) {
                             btn.find('span').text(msg);
                        } else {
                             btn.text(msg);
                        }
                        setTimeout(function() {
                            btn.html(originalHtml);
                            btn.prop('disabled', false);
                        }, 2000);
                    }
                }
            });
        },

        processBuyNow: function(productId, qty, variantKey, btn) {
            const originalHtml = btn.data('original-html') || btn.html();
            if (!btn.data('original-html')) btn.data('original-html', originalHtml);
            
            btn.text('Processing...').prop('disabled', true);

            $.ajax({
                url: '/cart/buy-now',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    product_id: productId,
                    qty: qty,
                    variant_key: variantKey,
                    store_subdomain: this.storeSlug
                },
                success: function(response) {
                    if (response.success && response.redirect_url) {
                        window.location.href = response.redirect_url;
                    }
                },
                error: function(xhr) {
                    console.error('Buy Now failed', xhr);
                    let msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Failed to process';
                    StorefrontCart.showStockToast(msg);
                    
                    btn.html(originalHtml).prop('disabled', false);
                    $('#btnCheckoutSingleItem').html('Checkout ONLY this item').prop('disabled', false);
                    $('#buyNowConflictModal').modal('hide');
                }
            });
        },

        markBtnMaxed: function(btn, isIconBtn) {
            btn.addClass('stock-maxed');
            btn.css({ 'color': '#9ca3af', 'border-color': '#d1d5db', 'cursor': 'not-allowed', 'background': '' });
            // Works for both <a> (icon btn with inner <span>) and plain <button> elements
            if (btn.find('span').length) {
                btn.find('span').first().html('<span style="font-size:12px;">Out of stock</span>');
            } else {
                btn.html('Out of stock');
            }
            btn.prop('disabled', false); // keep clickable so guard fires in bindEvents
            // Also disable qty selector on product detail page if present
            $('#qtyDecBtn, #qtyIncBtn, #quantity').prop('disabled', true).css('opacity', '0.4');
            // Hide Buy Now button
            $('#buyNowBtn').hide();
        },

        showStockToast: function(message) {
            // Remove existing toast if any
            $('#stock-limit-toast').remove();
            const toast = $(`
                <div id="stock-limit-toast" style="
                    position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%);
                    background: #1f2937; color: #fff; padding: 0.75rem 1.25rem;
                    border-radius: 0.5rem; font-size: 0.875rem; z-index: 99999;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.25); max-width: 380px; text-align: center;
                ">
                    <i class="fas fa-exclamation-circle me-2" style="color:#f59e0b;"></i>
                    ${message}
                </div>
            `);
            $('body').append(toast);
            setTimeout(function() { toast.fadeOut(400, function() { $(this).remove(); }); }, 4000);
        },

        refreshCart: function() {
            $.ajax({
                url: '/cart/json',
                type: 'GET',
                success: function(response) {
                    StorefrontCart.updateCartCount(response.item_count);
                    StorefrontCart.renderCart(response);
                    // On page load, mark any buttons for products already at stock limit
                    StorefrontCart.syncMaxedButtons(response);
                }
            });
        },

        // Called on page load and after each add — marks buttons for maxed-out products
        syncMaxedButtons: function(cart) {
            if (!cart.items) return;
            cart.items.forEach(function(item) {
                if (item.max_stock === null || item.max_stock === undefined) return;
                if (item.qty < item.max_stock) return;

                // Mark any matching add-to-cart button on the page
                const selectors = [
                    `.add-to-cart-btn-index[data-product-id="${item.product_id}"]`,
                    `#addToCartDetails[data-product-id="${item.product_id}"]`
                ];
                selectors.forEach(function(sel) {
                    const btn = $(sel);
                    if (btn.length && !btn.hasClass('stock-maxed')) {
                        const isIconBtn = btn.hasClass('m-btn');
                        StorefrontCart.markBtnMaxed(btn, isIconBtn);
                    }
                });
            });
        },

        updateCartCount: function(count) {
            this.cartItemCount = parseInt(count) || 0;
            $('.cart-toggle-btn span').text(count);
        },

        renderCart: function(cart) {
            const list = $('#cartMiniList');
            list.empty();
            
            $('#cartMiniSubtotal').text( new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(cart.subtotal / 100) );

            if (cart.items && cart.items.length > 0) {
                cart.items.forEach(function(item) {
                    let mediaHtml = '';
                    if (item.image) {
                        // Check if it's a video by file extension
                        const imagePath = `/storage/${item.image}`;
                        const ext = item.image.split('.').pop().toLowerCase();
                        const isVideo = ['mp4', 'webm', 'mov', 'avi', 'mpeg'].includes(ext);
                        
                        if (isVideo) {
                            const mimeType = ext === 'mov' ? 'quicktime' : ext;
                            mediaHtml = `
                                <div style="position: relative;">
                                    <video style="width: 100%; height: 70px; object-fit: cover;" muted>
                                        <source src="${imagePath}" type="video/${mimeType}">
                                    </video>
                                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none;">
                                        <i class="fas fa-play-circle text-white" style="font-size: 1.5rem; opacity: 0.9;"></i>
                                    </div>
                                </div>
                            `;
                        } else {
                            mediaHtml = `<img src="${imagePath}" alt="${item.name}">`;
                        }
                    } else {
                        @php
                            $assetBaseUrl = "";
                        @endphp
                        mediaHtml = `<img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="${item.name}">`;
                    }

                    const html = `
                        <li>
                            <div class="cartmini__thumb">
                                <a href="#">
                                    ${mediaHtml}
                                </a>
                            </div>
                            <div class="cartmini__content">
                                <h5><a href="#">${item.name}</a></h5>
                                <div class="product-quantity mt-10 mb-10">
                                    <span class="cart-minus" onclick="StorefrontCart.updateItemQty('${item.id}', ${item.qty - 1})">-</span>
                                    <input class="cart-input" type="text" value="${item.qty}" readonly/>
                                    <span class="cart-plus" onclick="StorefrontCart.updateItemQty('${item.id}', ${item.qty + 1})">+</span>
                                </div>
                                <div class="product__sm-price-wrapper">
                                    <span class="product__sm-price">${new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(item.line_subtotal / 100)}</span>
                                </div>
                            </div>
                            <a href="javascript:void(0);" class="cartmini__del" onclick="StorefrontCart.removeItem('${item.id}')"><i class="fal fa-times"></i></a>
                        </li>
                    `;
                    list.append(html);
                });
            } else {
                list.append('<li><div class="cartmini__content"><h5>Your cart is empty</h5></div></li>');
            }
        },

        updateItemQty: function(itemId, newQty) {
            if (newQty < 1) return;
             $.ajax({
                url: '/cart/item/' + itemId,
                type: 'PATCH',
                data: {
                    _token: '{{ csrf_token() }}',
                    qty: newQty,
                    store_slug: this.storeSlug
                },
                success: function(response) {
                     StorefrontCart.updateCartCount(response.item_count);
                     StorefrontCart.renderCart(response);
                },
                error: function(xhr) {
                    console.error('Update item failed', xhr);
                }
            });
        },

        removeItem: function(itemId) {
            $.ajax({
                url: '/cart/item/' + itemId,
                type: 'DELETE',
                data: {
                    _token: '{{ csrf_token() }}',
                    store_slug: this.storeSlug
                },
                success: function(response) {
                     StorefrontCart.updateCartCount(response.item_count);
                     StorefrontCart.renderCart(response);
                },
                error: function(xhr) {
                    console.error('Remove item failed', xhr);
                }
            });
        }
    };

    $(document).ready(function() {
        StorefrontCart.init();
    });
</script>
