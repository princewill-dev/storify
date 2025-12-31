<header>
   <div class="header__area header__shadow-2 " id="header-sticky">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-4 col-6">
               <div class="logo">
                  <a href="{{ $store ? store_url($store->slug) : route('home.index') }}">
                     @if($store && $store->logo_path)
                        <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}" style="max-height: 50px;">
                     @else
                        <img src="{{ asset('storefront/assets/img/logo/logo-white.png') }}" alt="{{ $store->name ?? 'Store' }}">
                     @endif
                  </a>
               </div>
            </div>
            <div class="col-xxl-7 col-xl-7 col-lg-8 d-none d-lg-block">
               <div class="main-menu d-flex justify-content-end">
                  <nav id="mobile-menu">
                     <ul>
                        <li class="active"><a href="{{ $store ? store_url($store->slug) : route('home.index') }}">Home</a></li>

                         @if(isset($headerCategories) && $headerCategories->count() > 0)
                            <li class="has-dropdown">
                               <a href="javascript:void(0)">Categories</a>
                               <ul class="submenu">
                                  @foreach($headerCategories as $cat)
                                    <li><a href="{{ route('home.store.category', ['store_subdomain' => $store->slug, 'category' => $cat->slug]) }}">{{ $cat->name }}</a></li>
                                  @endforeach
                               </ul>
                            </li>
                         @endif

                        <li><a href="{{ route('home.support.index', ['store_subdomain' => $store->slug]) }}">Support</a></li>

                        <li><a href="{{ route('home.store.products', ['store_subdomain' => $store->slug]) }}">Products</a></li>

                        <li><a href="{{ route('home.store.services', ['store_subdomain' => $store->slug]) }}">Services</a></li>

                        <li><a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug]) }}">Track Order</a></li>
                     </ul>
                  </nav>
               </div>
            </div>
            <div class="col-xxl-3 col-xl-3 col-lg-2 col-md-8 col-6">
               <div class="header__action d-flex align-items-center justify-content-end">
                  
                  <a href="javascript:void(0);" class="cart-toggle-btn" style="position: relative; display: inline-flex; padding: 8px; color: #333; margin-right: 15px;">
                     <i class="fas fa-shopping-cart" style="font-size: 24px;"></i>
                     <span style="position: absolute; top: -5px; right: -5px; background: #7c5cfc; color: #fff; font-size: 12px; font-weight: 600; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">0</span>
                  </a>

                  <div class="sidebar__menu d-lg-none">
                     <div class="sidebar-toggle-btn" id="sidebar-toggle">
                           <span class="line"></span>
                           <span class="line"></span>
                           <span class="line"></span>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
</header>