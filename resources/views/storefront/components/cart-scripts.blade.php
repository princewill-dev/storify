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
                
                let variantKey = null;
                const sizeBtn = $('.size-btn.active');
                let size = sizeBtn.length ? sizeBtn.text().trim() : '';
                
                if (size) {
                    variantKey = size + '||'; 
                }
                
                StorefrontCart.addToCart(productId, qty, variantKey, btn);
            });

            // Buy Now Button
            $('#buyNowBtn').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const productId = btn.data('product-id');
                const qty = $('#quantity').val() || 1;
                
                let variantKey = null;
                const sizeBtn = $('.size-btn.active');
                let size = sizeBtn.length ? sizeBtn.text().trim() : '';
                
                if (size) {
                    variantKey = size + '||'; 
                }
                
                StorefrontCart.addToCart(productId, qty, variantKey, btn, function() {
                    window.location.href = "{{ route('checkout.index', ['store_slug' => $store->slug]) }}";
                });
            });
        },

        addToCart: function(productId, qty, variantKey, btn, successCallback) {
            const originalText = btn.data('original-text') || btn.html(); 
            if (!btn.data('original-text')) btn.data('original-text', originalText);
            
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
                    store_slug: this.storeSlug 
                },
                success: function(response) {
                    if (isIconBtn) {
                         btn.find('span').text('Added!');
                    } else {
                         btn.text('Added!');
                    }
                    
                    StorefrontCart.updateCartCount(response.item_count);
                    StorefrontCart.renderCart(response);
                    
                    if (successCallback) {
                        successCallback(response);
                        return; // Skip reverting button if redirecting
                    }

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
