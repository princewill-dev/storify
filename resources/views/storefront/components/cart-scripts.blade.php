<script>
    const StorefrontCart = {
        storeSlug: "{{ $store->slug ?? 'store' }}",
        
        init: function() {
            this.refreshCart();
            this.bindEvents();
        },

        bindEvents: function() {
            // Index page Add to Cart buttons
            $(document).on('click', '.add-to-cart-btn-index', function(e) {
                e.preventDefault();
                const btn = $(this);
                const productId = btn.data('product-id');
                StorefrontCart.addToCart(productId, 1, null, btn);
            });

            // Product Details Add to Cart button
            $('#addToCartDetails').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const productId = btn.data('product-id');
                const qty = $('#quantity').val() || 1;
                
                // Construct variant key if variants exist
                let variantKey = null;
                const sizeBtn = $('.size-btn.active');
                const colorBtn = $('.color-btn.active'); // Assuming there might be color buttons later or currently
                // Based on PHP: size|weight|color
                // Currently only size selector is visible in blade snippet
                let size = sizeBtn.length ? sizeBtn.text().trim() : '';
                
                // For now, we only have size selector visible in the snippet provided. 
                // We'll construct the key as best we can. 
                // If the product has variants, we might need a more robust way to gather all attributes.
                // But for now, let's pass the size if selected.
                
                // Actually, let's try to pass null if no intricate selection to avoid breaking 'index' logic
                // But for details page, if size selected, we use it.
                if (size) {
                    // Start of key logic matching controller/view:
                    // key = ($sizeKey ?? '') . '|' . ($weightKey ?? '') . '|' . ($colorKey ?? '');
                    // The view renders the OPTIONS which are the formatted keys.
                    // So we can just use the text.
                    // But we need to be careful about the | separators.
                    // We don't have weight/color selectors in the snippet (only size).
                    // We'll assume just size for now or adapt if we see more.
                    // Update: The snippet shows size-selector.
                    variantKey = size + '||'; // Assuming weight and color are empty for now if not selected
                }
                
                StorefrontCart.addToCart(productId, qty, variantKey, btn);
            });
        },

        addToCart: function(productId, qty, variantKey, btn) {
            const originalText = btn.data('original-text') || btn.html(); // Use html to preserve icons/styles
            if (!btn.data('original-text')) btn.data('original-text', originalText);
            
            // Set loading state
            // Check if it's the index button (anchor) or details button (button) to format "Adding..."
            const isIconBtn = btn.hasClass('m-btn');
            
            if (isIconBtn) {
                 btn.find('span').text('Adding..');
            } else {
                 btn.text('Adding..');
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
                    store_slug: this.storeSlug // Pass store slug just in case, though route handles it via subdomain usually
                },
                success: function(response) {
                    if (isIconBtn) {
                         btn.find('span').text('Added!');
                    } else {
                         btn.text('Added!');
                    }
                    
                    StorefrontCart.updateCartCount(response.item_count);
                    StorefrontCart.renderCart(response);
                    
                    // Revert button after delay
                    setTimeout(function() {
                        if (isIconBtn) {
                            btn.html(originalText);
                        } else {
                            btn.html(originalText);
                        }
                        btn.prop('disabled', false);
                    }, 2000);
                },
                error: function(xhr) {
                    console.error('Add to cart failed', xhr);
                    let msg = 'Failed';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    if (isIconBtn) {
                         btn.find('span').text(msg);
                    } else {
                         btn.text(msg);
                    }
                    
                    setTimeout(function() {
                        if (isIconBtn) {
                            btn.html(originalText);
                        } else {
                            btn.html(originalText);
                        }
                        btn.prop('disabled', false);
                    }, 2000);
                }
            });
        },

        refreshCart: function() {
            $.ajax({
                url: '/cart/json',
                type: 'GET',
                success: function(response) {
                    StorefrontCart.updateCartCount(response.item_count);
                    StorefrontCart.renderCart(response);
                }
            });
        },

        updateCartCount: function(count) {
            $('.cart-toggle-btn span').text(count);
        },

        renderCart: function(cart) {
            const list = $('#cartMiniList');
            list.empty();
            
            $('#cartMiniSubtotal').text( new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(cart.subtotal / 100) );

            if (cart.items && cart.items.length > 0) {
                cart.items.forEach(function(item) {
                    let imageHtml = '';
                    if (item.image) {
                        imageHtml = `<img src="/storage/${item.image}" alt="${item.name}">`;
                    } else {
                        @php
                            $mainDomain = config('app.main_domain', 'storify.ng');
                            $scheme = request()->secure() ? 'https' : 'http';
                            $assetBaseUrl = "{$scheme}://{$mainDomain}";
                        @endphp
                        imageHtml = `<img src="{{ $assetBaseUrl }}/Storefront/assets/img/product/product-1.jpg" alt="${item.name}">`;
                    }

                    const html = `
                        <li>
                            <div class="cartmini__thumb">
                                <a href="#">
                                    ${imageHtml}
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
