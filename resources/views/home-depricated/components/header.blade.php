@php
    $brandStore = $brandStore ?? $store ?? null;
    $brandLogo = $brandLogo ?? ($brandStore?->logo_path ? asset('storage/'.$brandStore->logo_path) : $company->logo);
    $brandUrl = $brandUrl ?? ($brandStore ? route('home.store.products.index', ['store_slug' => $brandStore->slug]) : route('home.index'));
@endphp


<header class="site-header mo-left header style-1 header-transparent">		
    <!-- Main Header -->
    <div class="sticky-header main-bar-wraper navbar-expand-lg">
        <div class="main-bar clearfix">
            <div class="container-fluid clearfix">
                <!-- Website Logo -->
                
                <div class="logo-header logo-dark me-md-5">
                    <a href="{{ $brandUrl }}"><img src="{{ $brandLogo }}" alt="logo"></a>
                </div>
                
                <!-- Nav Toggle Button -->
                <button class="navbar-toggler collapsed navicon justify-content-end" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                
                <!-- EXTRA NAV -->
                <div class="extra-nav">
                    <div class="extra-cell">						
                        <ul class="header-right">
                            
                            <li class="nav-item search-link">
                                <a class="nav-link"  href="javascript:void(0);" data-bs-toggle="offcanvas" data-bs-target="#offcanvasTop" aria-controls="offcanvasTop">
                                    <svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <circle cx="10.0535" cy="10.55" r="7.49047" stroke="#000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M15.2632 16.1487L18.1999 19.0778" stroke="#000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </li>
                            <li class="nav-item cart-link">
                                <a href="javascript:void(0);" class="nav-link cart-btn"  data-bs-toggle="offcanvas" data-bs-target="#offcanvasRight" aria-controls="offcanvasRight">
                                    <svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M1.08374 2.61947C1.08374 2.27429 1.36356 1.99447 1.70874 1.99447H3.29314C3.91727 1.99447 4.4722 2.39163 4.67352 2.98239L5.06379 4.1276H15.4584C17.6446 4.1276 19.4168 5.89981 19.4168 8.08593V11.5379C19.4168 13.7241 17.6446 15.4963 15.4584 15.4963H9.22182C7.30561 15.4963 5.66457 14.1237 5.32583 12.2377L4.00967 4.90953L3.49034 3.3856C3.46158 3.30121 3.3823 3.24447 3.29314 3.24447H1.70874C1.36356 3.24447 1.08374 2.96465 1.08374 2.61947ZM5.36374 5.3776L6.55614 12.0167C6.78791 13.3072 7.91073 14.2463 9.22182 14.2463H15.4584C16.9542 14.2463 18.1668 13.0337 18.1668 11.5379V8.08593C18.1668 6.59016 16.9542 5.3776 15.4584 5.3776H5.36374Z" fill="#000"/>
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M8.16479 17.8278C8.16479 17.1374 8.72444 16.5778 9.4148 16.5778H9.42313C10.1135 16.5778 10.6731 17.1374 10.6731 17.8278C10.6731 18.5182 10.1135 19.0778 9.42313 19.0778H9.4148C8.72444 19.0778 8.16479 18.5182 8.16479 17.8278Z" fill="#000"/>
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M14.8315 17.8278C14.8315 17.1374 15.3912 16.5778 16.0815 16.5778H16.0899C16.7802 16.5778 17.3399 17.1374 17.3399 17.8278C17.3399 18.5182 16.7802 19.0778 16.0899 19.0778H16.0815C15.3912 19.0778 14.8315 18.5182 14.8315 17.8278Z" fill="#000"/>
                                    </svg>
                                    <span class="badge badge-circle" id="js-cart-badge">0</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Main Nav -->
                <div class="header-nav navbar-collapse collapse justify-content-start" id="navbarNavDropdown">
                    <div class="logo-header logo-dark">
                        <a href="{{ $brandUrl }}"><img src="{{ $brandLogo }}" alt="logo"></a>
                    </div>
                    <ul class="nav navbar-nav dark-nav">

                        <li><a href="{{ $brandUrl }}">Home</a></li>
                        
                        <li class="sub-menu sub-menu-down"><a href="javascript:void(0);"><span>Shop</span></a>
                            <ul class="sub-menu">
                                @forelse(($navServices ?? collect()) as $svc)
                                    <li><a href="{{ url('/'.$svc->page_link) }}">{{ $svc->title }}</a></li>
                                @empty
                                    <li><span class="text-muted px-3">No services</span></li>
                                @endforelse
                            </ul>
                        </li>

                        <li><a href="{{ route('tracking.index') }}">Track Order</a></li>

                        <li><a href="{{ route('home.support.index', ['store_slug' => $brandStore->slug ?? $mainStore->slug] ) }}">Support</a></li>

                        @auth('vendor')
                        <li><a href="{{ route('vendor.dashboard') }}">Dashboard</a></li>
                        @else
                        <li class="sub-menu sub-menu-down"><a href="javascript:void(0);"><span>Vendor</span></a>
                            <ul class="sub-menu">
                                <li><a href="{{ route('vendor.auth.register') }}">Become Vendor</a></li>
                                <li><a href="{{ route('vendor.auth.login') }}"> Login</a></li>
                            </ul>
                        </li>

                        @auth('customer')
                        <li><a href="{{ route('account.dashboard') }}">My Account</a></li>
                        @else
                        <li class="sub-menu sub-menu-down"><a href="javascript:void(0);"><span>Account</span></a>
                            <ul class="sub-menu">
                                <li><a href="{{ route('account.login') }}">Login</a></li>
                                <li><a href="{{ route('account.register') }}">Register</a></li>
                            </ul>
                        </li>
                        @endauth
                        @endauth
                    </ul>
                    
                    <div class="dz-social-icon">
                        <ul>
                            <li><a class="fab fa-facebook-f" target="_blank" href="javascript:void(0);"></a></li>
                            <li><a class="fab fa-twitter" target="_blank" href="javascript:void(0);"></a></li>
                            <li><a class="fab fa-linkedin-in" target="_blank" href="https://www.linkedin.com/showcase/3686700/admin/"></a></li>
                            <li><a class="fab fa-instagram" target="_blank" href="javascript:void(0);"></a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Main Header End -->
    
    
    <!-- SearchBar -->
    <div class="dz-search-area dz-offcanvas offcanvas offcanvas-top" tabindex="-1" id="offcanvasTop">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close">
            &times;
        </button>
        <div class="container" style="overflow: visible !important;">
            <form class="header-item-search" onsubmit="return false;" style="overflow: visible !important;">
                <div class="input-group search-input" style="position: relative; overflow: visible !important;">
                    <input type="text" id="live-search-input" class="form-control" aria-label="Text input with dropdown button" placeholder="Search Product" autocomplete="off" autofocus>
                    <button class="btn" type="button" onclick="document.getElementById('live-search-input').focus();">
                        <svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10.0535" cy="10.5399" r="7.49047" stroke="#0D775E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M15.2632 16.1387L18.1999 19.0677" stroke="#0D775E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <button type="button" id="clear-search-btn" class="btn" style="display:none; position:absolute; right:50px; top:50%; transform:translateY(-50%); z-index:5; padding:0; width:24px; height:24px; background:transparent; border:none;" onclick="clearLiveSearch();">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4L4 12M4 4L12 12" stroke="#999" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                    <div id="live-search-results" style="display:none; position:absolute !important; top:100%; left:0; right:0; margin-top:2px; background:#fff !important; border:1px solid #ddd; border-top:none; border-radius:0 0 8px 8px; max-height:400px; overflow-y:auto; z-index:99999 !important; box-shadow:0 4px 12px rgba(0,0,0,0.15); visibility:visible !important;"></div>
                </div>
                <!-- <ul class="recent-tag">
                    <li class="pe-0"><span>Quick Search :</span></li>
                    <li><a href="shop-list.html">Wooden Products</a></li>
                    <li><a href="shop-list.html">Metal Products</a></li>
                    <li><a href="shop-list.html">Baby Products</a></li>
                    <li><a href="shop-list.html">Yoga Mats</a></li>
                </ul> -->
            </form>

            @if(isset($suggestedProducts) && $suggestedProducts->count() > 0)
            <div class="row mt-4">
                <div class="col-xl-12">
                    <h5 class="mb-3">You May Also Like</h5>
                    <div class="swiper category-swiper2">
                        <div class="swiper-wrapper">
                            @foreach($suggestedProducts as $product)
                            <div class="swiper-slide">
                                <a href="{{ route('home.products.show', ['store_slug' => $product->store->slug, 'slug' => $product->slug, 'code' => $product->product_code]) }}">
                                    <div class="shop-card">
                                        <div class="dz-media">
                                            @php($firstImage = optional($product->images->first())->path)
                                            @if($firstImage)
                                                <img src="{{ asset('storage/'.$firstImage) }}" alt="{{ $product->name }}" style="max-height:120px;max-width:100%;object-fit:cover;">
                                            @else
                                                <img src="{{ asset('home/images/no-image.jpg') }}" alt="{{ $product->name }}" style="max-height:120px;max-width:100%;object-fit:cover;">
                                            @endif
                                        </div>
                                        <div class="dz-content">
                                            <h6 class="title" style="font-size:13px; margin-bottom:4px;">{{ Str::limit($product->name, 30) }}</h6>
                                            <h6 class="price" style="color:#0D775E; font-weight:600;">₦{{ number_format($product->amount ?? 0, 2) }}</h6>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            @endif
            
        </div>
    </div>
    <!-- SearchBar -->
    
    <!-- Sidebar cart -->
    <div class="offcanvas dz-offcanvas offcanvas offcanvas-end " tabindex="-1" id="offcanvasRight">
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close">
            &times;
        </button>
        <div class="offcanvas-body">
            <div class="product-description">
                <div class="dz-tabs">
                    <ul class="nav nav-tabs center" id="myTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="shopping-cart" data-bs-toggle="tab" data-bs-target="#shopping-cart-pane" type="button" role="tab" aria-controls="shopping-cart-pane" aria-selected="true">Shopping Cart
                                <span class="badge badge-light" id="js-cart-tab-badge">0</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content pt-4" id="dz-shopcart-sidebar">
                        <div class="tab-pane fade show active" id="shopping-cart-pane" role="tabpanel" aria-labelledby="shopping-cart" tabindex="0">
                            <div class="shop-sidebar-cart">
                                <ul class="sidebar-cart-list" id="js-cart-list"></ul>
                                <div class="cart-total">
                                    <h5 class="mb-0">Subtotal:</h5>
                                    <h5 class="mb-0" id="js-cart-subtotal">₦0.00</h5>
                                </div>
                                <div class="mt-auto">
                                    <a href="#" id="js-cart-checkout" class="btn btn-light btn-block m-b20">Checkout</a>	
                                    <a href="#" id="js-view-cart" class="btn btn-secondary btn-block">View Cart</a>	
                                </div>	
                            </div>	
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Sidebar cart -->
    
</header>


<script>
    (function(){
    var mainStoreSlug = @json($mainStore->slug ?? null);
    
    function findStoreSlug(){
        var seg = (location.pathname || '/').split('/').filter(Boolean)[0] || '';
        if (!seg) return mainStoreSlug;
        // If path starts with 'account', use main store slug
        if (seg === 'account') return mainStoreSlug;
        if (!/^[A-Za-z0-9_\-]+$/.test(seg)) return mainStoreSlug;
        return seg;
    }
    function fmtNgn(kobo){
        var n = (kobo||0)/100;
        return new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(n);
    }
    function csrf(){
        var m=document.querySelector('meta[name="csrf-token"]');
        return m?m.getAttribute('content'):'';
    }
    function renderCart(data){
        var list=document.getElementById('js-cart-list');
        var badge=document.getElementById('js-cart-badge');
        var tabBadge=document.getElementById('js-cart-tab-badge');
        var subtotal=document.getElementById('js-cart-subtotal');
        var checkoutBtn=document.getElementById('js-cart-checkout');
        if (badge) badge.textContent = data.item_count||0;
        if (tabBadge) tabBadge.textContent = data.item_count||0;
        if (subtotal) subtotal.textContent = fmtNgn(data.subtotal||0);
        // Update checkout button URL with current store slug
        if (checkoutBtn) {
            var slug = findStoreSlug();
            checkoutBtn.href = slug ? ('/'+slug+'/checkout') : '#';
        }
        if (!list) return;
        list.innerHTML = '';
        (data.items||[]).forEach(function(it){
        var imgSrc = it.image ? ('/storage/'+it.image) : '';
        var imgHtml = imgSrc ? ('<div class="dz-media me-3"><img src="'+imgSrc+'" alt=""></div>') : '<div class="dz-media me-3"></div>';
        var priceDisplay = fmtNgn(it.unit_amount||0);
        if (it.is_bulk) {
            priceDisplay = (it.qty||1) + ' x ' + priceDisplay;
        }
        var li=document.createElement('li');
        li.innerHTML = '\n        <div class="cart-widget">\n          '+imgHtml+'\n          <div class="cart-content">\n            <h6 class="title"><a href="#">'+(it.name||'')+'</a></h6>\n            <div class="d-flex align-items-center">\n              <div class="btn-quantity light quantity-sm me-3">\n                <input type="number" min="1" value="'+(it.qty||1)+'" data-item-id="'+it.id+'" class="js-cart-qty">\n              </div>\n              <h6 class="dz-price text-primary mb-0">'+priceDisplay+'</h6>\n            </div>\n          </div>\n          <a href="javascript:void(0);" class="dz-close" data-remove-item="'+it.id+'"><i class="fa fa-times"></i></a>\n        </div>\n      ';
        list.appendChild(li);
        });
    }
    function apiBase(overrideSlug){
        var slug = (overrideSlug && overrideSlug.trim()) || findStoreSlug();
        return slug?('/'+slug):'';
    }
    function fetchCart(storeSlug){
        var base=apiBase(storeSlug); if(!base) return;
        fetch(base+'/cart/json', {credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json().catch(()=>({items:[],item_count:0,subtotal:0})); })
        .then(renderCart).catch(()=>{});
    }
    function addToCart(productId, qty, variantKey, storeSlug){
        var base=apiBase(storeSlug); if(!base) return Promise.resolve();
        return fetch(base+'/cart/add', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()},
        body: JSON.stringify({product_id: productId, qty: qty||1, variant_key: variantKey||null})
        }).then(function(r){ return r.json().catch(()=>{ return {items:[],item_count:0,subtotal:0}; }); })
        .then(function(data){ renderCart(data); return data; });
    }
    function updateQty(itemId, qty){
        var base=apiBase(); if(!base) return;
        fetch(base+'/cart/item/'+itemId, {method:'PATCH', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()}, body: JSON.stringify({qty: qty})})
        .then(function(r){ return r.json().catch(()=>({items:[],item_count:0,subtotal:0})); }).then(renderCart).catch(()=>{});
    }
    function removeItem(itemId){
        var base=apiBase(); if(!base) return;
        fetch(base+'/cart/item/'+itemId, {method:'DELETE', credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()}})
        .then(function(r){ return r.json().catch(()=>({items:[],item_count:0,subtotal:0})); }).then(renderCart).catch(()=>{});
    }
    function clearCart(){
        var base=apiBase(); if(!base) return;
        fetch(base+'/cart/clear', {method:'DELETE', credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()}})
        .then(function(r){ return r.json().catch(()=>({items:[],item_count:0,subtotal:0})); }).then(renderCart).catch(()=>{});
    }
    document.addEventListener('click', function(e){
        var t=e.target.closest('[data-remove-item]');
        if (t){ e.preventDefault(); removeItem(t.getAttribute('data-remove-item')); }
    });
    document.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('js-cart-qty')){
        var id=e.target.getAttribute('data-item-id');
        var v=Math.max(1, parseInt(e.target.value||'1',10));
        e.target.value = v;
        updateQty(id, v);
        }
    });
    document.addEventListener('DOMContentLoaded', function(){
        var slug=findStoreSlug();
        var viewCart=document.getElementById('js-view-cart');
        if (viewCart && slug) viewCart.setAttribute('href', '/'+slug+'/cart');
        fetchCart();
    });
    // Generic click handler for Add To Cart buttons
    document.addEventListener('click', function(e){
        var btn = e.target.closest('[data-add-to-cart]');
        if (!btn) return;
        e.preventDefault();
        var pid = parseInt(btn.getAttribute('data-product-id')||'0',10);
        if (!pid) return;
        var store = btn.getAttribute('data-store')||'';
        var vkey = btn.getAttribute('data-variant-key')||null;
        var qsel = btn.getAttribute('data-qty-selector')||'';
        var qty = 1;
        if (qsel){ var qEl = document.querySelector(qsel); if (qEl){ qty = Math.max(1, parseInt(qEl.value||'1',10)); } }
        else {
        // try to find qty input near the button
        var qNear = btn.closest('.dz-product-detail, .shop-card, .cart-detail')?.querySelector('input[name="qty"], input[type="number"]');
        if (qNear) qty = Math.max(1, parseInt(qNear.value||'1',10));
        }
        addToCart(pid, qty, vkey, store).then(function(){
        // open the mini cart offcanvas if present
        try { new bootstrap.Offcanvas(document.getElementById('offcanvasRight')).show(); } catch(_){}
        });
    });
    window.CartAPI = { fetchCart, addToCart, updateQty, removeItem, clearCart };

    // Live Search
    (function(){
        var searchInput = document.getElementById('live-search-input');
        var searchResults = document.getElementById('live-search-results');
        var clearBtn = document.getElementById('clear-search-btn');
        var debounceTimer = null;
        var appUrl = @json(config('app.url'));

        if (!searchInput) return;

        function showLoading() {
            searchResults.innerHTML = '<div style="padding:16px; text-align:center; color:#666;"><div style="display:inline-block; width:20px; height:20px; border:3px solid #f3f3f3; border-top:3px solid #0D775E; border-radius:50%; animation:spin 1s linear infinite;"></div><style>@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style><div style="margin-top:8px; font-size:13px;">Searching...</div></div>';
            searchResults.style.display = 'block';
        }

        function performSearch(query) {
            console.log('[Live Search] performSearch called', {query: query, length: query.length});
            
            if (query.length === 1) {
                searchResults.innerHTML = '<div style="padding:16px; color:#999; text-align:center; font-size:13px;">Type at least 2 characters to search</div>';
                searchResults.style.display = 'block';
                return;
            }
            
            if (query.length < 1) {
                console.log('[Live Search] Query too short, hiding results');
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
                return;
            }

            var storeSlug = findStoreSlug();
            console.log('[Live Search] Store slug:', storeSlug);
            
            if (!storeSlug) {
                console.error('[Live Search] No store slug found');
                return;
            }

            showLoading();

            var url = appUrl + '/' + storeSlug + '/search?q=' + encodeURIComponent(query);
            console.log('[Live Search] Fetching:', url);

            fetch(url, {
                method: 'GET',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
            .then(function(r){ 
                console.log('[Live Search] Response status:', r.status);
                return r.json(); 
            })
            .then(function(data){
                console.log('[Live Search] Data received:', data);
                renderResults(data.products || []);
            })
            .catch(function(err){ 
                console.error('[Live Search] Error:', err);
                searchResults.innerHTML = '<div style="padding:16px; color:#d32f2f; text-align:center;">Error loading results</div>';
                searchResults.style.display = 'block';
            });
        }

        function renderResults(products) {
            console.log('[Live Search] renderResults called', {count: products.length});
            
            if (products.length === 0) {
                searchResults.innerHTML = '<div style="padding:16px; color:#666; text-align:center;">No products found</div>';
                searchResults.style.display = 'block';
                console.log('[Live Search] Showing no results message');
                return;
            }

            var html = '';
            products.forEach(function(p){
                var imgSrc = p.image || 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'50\' height=\'50\'%3E%3Crect fill=\'%23ddd\' width=\'50\' height=\'50\'/%3E%3C/svg%3E';
                var price = parseFloat(p.price || 0);
                html += '<a href="'+p.url+'" style="display:flex; align-items:center; padding:12px 16px; text-decoration:none; color:#333; border-bottom:1px solid #eee; transition:background 0.15s;">';
                html += '<img src="'+imgSrc+'" alt="'+p.name+'" style="width:50px; height:50px; object-fit:cover; border-radius:4px; margin-right:12px;" onerror="this.src=\'data:image/svg+xml,%3Csvg xmlns=http://www.w3.org/2000/svg width=50 height=50%3E%3Crect fill=%23ddd width=50 height=50/%3E%3C/svg%3E\'">';
                html += '<div style="flex:1;"><div style="font-weight:600; font-size:14px;">'+p.name+'</div></div>';
                html += '<div style="font-weight:600; color:#0D775E; white-space:nowrap;">₦'+price.toFixed(2)+'</div>';
                html += '</a>';
            });
            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
            
            var rect = searchResults.getBoundingClientRect();
            var computed = window.getComputedStyle(searchResults);
            console.log('[Live Search] Results rendered and displayed', {
                display: searchResults.style.display,
                zIndex: computed.zIndex,
                position: computed.position,
                visibility: computed.visibility,
                overflow: computed.overflow,
                dimensions: {
                    width: rect.width,
                    height: rect.height,
                    top: rect.top,
                    left: rect.left,
                    bottom: rect.bottom,
                    right: rect.right
                },
                parentOverflow: window.getComputedStyle(searchResults.parentElement).overflow
            });

            // Add hover effect
            var links = searchResults.querySelectorAll('a');
            links.forEach(function(link){
                link.addEventListener('mouseenter', function(){ this.style.background='#f8f9fa'; });
                link.addEventListener('mouseleave', function(){ this.style.background='#fff'; });
            });
        }

        window.clearLiveSearch = function() {
            clearTimeout(debounceTimer);
            searchInput.value = '';
            searchResults.style.display = 'none';
            searchResults.innerHTML = '';
            clearBtn.style.display = 'none';
            searchInput.focus();
        };

        var debouncedSearch = function(){
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function(){
                var val = searchInput.value.trim();
                console.log('[Live Search] Debounced search triggered', {value: val});
                clearBtn.style.display = val ? 'block' : 'none';
                if (val) {
                    performSearch(val);
                } else {
                    searchResults.style.display = 'none';
                    searchResults.innerHTML = '';
                }
            }, 400);
        };

        searchInput.addEventListener('input', function(e){
            console.log('[Live Search] Input event triggered', {value: e.target.value});
            debouncedSearch();
        });

        searchInput.addEventListener('focus', function(){
            if (searchInput.value.trim() && searchResults.innerHTML) {
                searchResults.style.display = 'block';
            }
        });

        document.addEventListener('click', function(e){
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
    })();
    
    // Initialize Swiper for suggested products carousel
    if (document.querySelector('.category-swiper2')) {
        new Swiper('.category-swiper2', {
            slidesPerView: 2,
            spaceBetween: 15,
            loop: false,
            autoplay: {
                delay: 3000,
                disableOnInteraction: false,
            },
            breakpoints: {
                640: {
                    slidesPerView: 3,
                    spaceBetween: 15,
                },
                768: {
                    slidesPerView: 4,
                    spaceBetween: 20,
                },
                1024: {
                    slidesPerView: 6,
                    spaceBetween: 20,
                },
            },
        });
    }
    })();
</script>