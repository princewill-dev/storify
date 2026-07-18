<!doctype html>
<html class="no-js" lang="zxx">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="x-ua-compatible" content="ie=edge">
      <meta name="csrf-token" content="{{ csrf_token() }}">
      <title>{{ $store->name ?? 'Storify' }} | @yield('title')</title>
      <meta name="description" content="">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <!-- Place favicon.ico in the root directory -->
     
      @if($store && $store->logo_path)
         <link rel="shortcut icon" type="image/x-icon" href="{{ asset('storage/' . $store->logo_path) }}">
      @else
         <link rel="shortcut icon" type="image/x-icon" href="/storefront/assets/img/favicon.png">
      @endif
      <!-- CSS here -->
      <link rel="stylesheet" href="/storefront/assets/css/preloader.css">
      <link rel="stylesheet" href="/storefront/assets/css/bootstrap.min.css">
      <link rel="stylesheet" href="/storefront/assets/css/slick.css">
      <link rel="stylesheet" href="/storefront/assets/css/meanmenu.css">
      <link rel="stylesheet" href="/storefront/assets/css/owl.carousel.min.css">
      <link rel="stylesheet" href="/storefront/assets/css/animate.min.css">
      <link rel="stylesheet" href="/storefront/assets/css/backToTop.css">
      <link rel="stylesheet" href="/storefront/assets/css/jquery.fancybox.min.css">
      <link rel="stylesheet" href="/storefront/assets/css/fontAwesome5Pro.css">
      <link rel="stylesheet" href="/storefront/assets/css/elegantFont.css">
      <link rel="stylesheet" href="/storefront/assets/css/imagetooltip.min.css">
      <link rel="stylesheet" href="/storefront/assets/css/default.css">
      <link rel="stylesheet" href="/storefront/assets/css/style.css">

      @stack('styles')
      @stack('scripts')
   </head>
   <body>
      <!--[if lte IE 9]>
      <p class="browserupgrade">You are using an <strong>outdated</strong> browser. Please <a href="https://browsehappy.com/">upgrade your browser</a> to improve your experience and security.</p>
      <![endif]-->
      
      <!-- Add your site or application content here -->  

      <!-- pre loader area start -->
      <div id="loading">
         <div id="loading-center">
            <div id="loading-center-absolute">
               <div class="object" id="object_one"></div>
               <div class="object" id="object_two"></div>
               <div class="object" id="object_three"></div>
               <div class="object" id="object_four"></div>
               <div class="object" id="object_five"></div>
            </div>
         </div>  
      </div>
      <!-- pre loader area end -->

      <!-- back to top start -->
      <!-- <div class="progress-wrap">
         <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102">
            <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" />
         </svg>
      </div> -->
      <!-- back to top end -->

      <!-- header area start -->
      @include('storefront.components.header')
      <!-- header area end -->

      <!-- sidebar area start -->
      @include('storefront.components.cart')
      <!-- sidebar area end -->

      <!-- sidebar area start -->
      @include('storefront.components.sidebar')
      <!-- sidebar area end -->

      <div class="body-overlay"></div>
      <!-- sidebar area end -->


      <main>

         @yield('content')
          
      </main>

      {{-- Storefront Search Modal --}}
      <div id="storeSearchModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);align-items:flex-start;justify-content:center;padding-top:80px;">
         <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:80vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div style="display:flex;align-items:center;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
               <i class="fas fa-search" style="color:#94a3b8;font-size:16px;margin-right:12px;"></i>
               <input type="text" id="storeSearchInput" placeholder="Search products..." autocomplete="off"
                  style="flex:1;border:none;outline:none;font-size:15px;color:#1a1a1a;background:transparent;">
               <button onclick="closeStoreSearch()" style="border:none;background:none;color:#94a3b8;font-size:20px;cursor:pointer;padding:0 0 0 12px;">&times;</button>
            </div>
            <div id="storeSearchResults" style="max-height:60vh;overflow-y:auto;padding:8px 0;">
               <div style="text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px;">Start typing to search products</div>
            </div>
         </div>
      </div>

      <!-- footer area start -->
      @include('storefront.components.footer')
      <!-- footer area end -->

      <!-- JS here -->
      <!-- JS here -->
      <script src="/storefront/assets/js/vendor/jquery-3.5.1.min.js"></script>
      <script src="/storefront/assets/js/vendor/waypoints.min.js"></script>
      <script src="/storefront/assets/js/bootstrap.bundle.min.js"></script>
      <script src="/storefront/assets/js/jquery.meanmenu.js"></script>
      <script src="/storefront/assets/js/slick.min.js"></script>
      <script src="/storefront/assets/js/jquery.fancybox.min.js"></script>
      <script src="/storefront/assets/js/isotope.pkgd.min.js"></script>
      <script src="/storefront/assets/js/parallax.min.js"></script>
      <script src="/storefront/assets/js/owl.carousel.min.js"></script>
      <script src="/storefront/assets/js/backToTop.js"></script>
      <script src="/storefront/assets/js/jquery.counterup.min.js"></script>
      <script src="/storefront/assets/js/ajax-form.js"></script>
      <script src="/storefront/assets/js/wow.min.js"></script>
      <script src="/storefront/assets/js/imagetooltip.min.js"></script>
      <script src="/storefront/assets/js/imagesloaded.pkgd.min.js"></script>
      <script src="/storefront/assets/js/main.js"></script>
      @include('storefront.components.cart-scripts')

      {{-- Storefront Product Search --}}
      <script>
      let searchTimer;
      const searchModal = document.getElementById('storeSearchModal');
      const searchInput = document.getElementById('storeSearchInput');
      const searchResults = document.getElementById('storeSearchResults');

      function openStoreSearch() {
         searchModal.style.display = 'flex';
         searchInput.value = '';
         searchResults.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px;">Start typing to search products</div>';
         setTimeout(() => searchInput.focus(), 100);
      }

      function closeStoreSearch() {
         searchModal.style.display = 'none';
      }

      searchModal.addEventListener('click', function(e) {
         if (e.target === searchModal) closeStoreSearch();
      });

      document.addEventListener('keydown', function(e) {
         if (e.key === 'Escape' && searchModal.style.display === 'flex') closeStoreSearch();
      });

      searchInput.addEventListener('input', function() {
         clearTimeout(searchTimer);
         const q = this.value.trim();
         if (q.length < 2) {
            searchResults.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px;">Start typing to search products</div>';
            return;
         }
         searchResults.innerHTML = '<div style="text-align:center;padding:30px 20px;color:#94a3b8;font-size:13px;">Searching...</div>';
         searchTimer = setTimeout(() => doSearch(q), 300);
      });

      async function doSearch(q) {
         try {
            const resp = await fetch('/search?q=' + encodeURIComponent(q), {
               headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            const products = data.products || [];
            if (!products.length) {
               searchResults.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px;">No products found</div>';
               return;
            }
            searchResults.innerHTML = products.map(p => `
               <a href="${p.url}" style="display:flex;align-items:center;gap:14px;padding:12px 20px;text-decoration:none;color:inherit;transition:background 0.15s;"
                  onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                  <div style="width:48px;height:48px;border-radius:8px;overflow:hidden;background:#f1f5f9;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                     ${p.image ? `<img src="${p.image}" alt="${p.name}" style="width:100%;height:100%;object-fit:cover;">` : '<i class="fas fa-box" style="font-size:20px;color:#cbd5e1;"></i>'}
                  </div>
                  <div style="flex:1;min-width:0;">
                     <div style="font-size:14px;font-weight:500;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${p.name}</div>
                     <div style="font-size:13px;color:#94a3b8;margin-top:2px;">${p.product_code}</div>
                  </div>
                  <div style="font-size:14px;font-weight:600;color:#1a1a1a;flex-shrink:0;">${p.price > 0 ? '₦' + Number(p.price).toLocaleString() : '—'}</div>
               </a>
            `).join('');
         } catch(e) {
            searchResults.innerHTML = '<div style="text-align:center;padding:30px 20px;color:#ef4444;font-size:13px;">Search failed. Try again.</div>';
         }
      }
      </script>
   </body>
</html>

