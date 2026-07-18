<header>
   <style>
      .header-icon-link {
         position:relative;display:inline-flex;align-items:center;justify-content:center;
         width:36px;height:36px;border-radius:10px;color:#475569;text-decoration:none;
         transition:all 0.15s ease;font-size:17px;
      }
      .header-icon-link:hover { background:#f1f5f9;color:#0f172a; }
      .header-avatar {
         display:inline-flex;align-items:center;justify-content:center;
         width:30px;height:30px;border-radius:10px;background:#0f172a;color:#fff;
         font-size:12px;font-weight:600;letter-spacing:.02em;
      }
      .header-badge {
         position:absolute;top:-3px;right:-3px;background:#0f172a;color:#fff;
         font-size:10px;font-weight:600;min-width:17px;height:17px;border-radius:50px;
         display:flex;align-items:center;justify-content:center;padding:0 4px;
         line-height:1;
      }
      .main-menu ul li.active > a { color:#0f172a !important; font-weight:600; }
      .main-menu ul li > a { transition:color 0.15s; }
      .main-menu ul li > a:hover { color:#0f172a !important; }
   </style>
   <div class="header__area header__shadow-2 " id="header-sticky">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-4 col-6">
               <div class="logo">
                  <a href="{{ $store ? store_url($store->slug) : route('home.index') }}">
                     @if($store && $store->logo_path)
                        <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}" style="max-height: 50px;">
                     @else
                        <span style="font-size:20px;font-weight:700;color:#333;">{{ $store->name ?? 'Store' }}</span>
                     @endif
                  </a>
               </div>
            </div>
            <div class="col-xxl-7 col-xl-7 col-lg-8 d-none d-lg-block">
               <div class="main-menu d-flex justify-content-end">
                  <nav id="mobile-menu">
                     <ul>
                        <li class="{{ request()->routeIs('home.store.products.index', 'local.store.products.index') ? 'active' : '' }}">
                           <a href="{{ $store ? store_url($store->slug) : route('home.index') }}">Home</a>
                        </li>

                         @if(isset($headerCategories) && $headerCategories->count() > 0)
                            <li class="has-dropdown {{ request()->routeIs('home.store.category', 'local.store.category') ? 'active' : '' }}">
                               <a href="javascript:void(0)">Categories</a>
                               <ul class="submenu">
                                  @foreach($headerCategories as $cat)
                                    <li><a href="{{ route('home.store.category', ['store_subdomain' => $store->slug, 'category' => $cat->slug]) }}">{{ $cat->name }}</a></li>
                                  @endforeach
                               </ul>
                            </li>
                         @endif

                        <li class="{{ request()->routeIs('home.support.index', 'local.support.index') ? 'active' : '' }}">
                           <a href="{{ route('home.support.index', ['store_subdomain' => $store->slug]) }}">Support</a>
                        </li>

                        <li class="{{ request()->routeIs('home.store.products', 'home.products.show', 'local.store.products', 'local.products.show') ? 'active' : '' }}">
                           <a href="{{ route('home.store.products', ['store_subdomain' => $store->slug]) }}">Products</a>
                        </li>

                        <li class="{{ request()->routeIs('home.store.services', 'home.services.show', 'local.store.services', 'local.services.show') ? 'active' : '' }}">
                           <a href="{{ route('home.store.services', ['store_subdomain' => $store->slug]) }}">Services</a>
                        </li>

                        <li class="{{ request()->routeIs('home.store.order.track') ? 'active' : '' }}">
                           <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug]) }}">Track Order</a>
                        </li>
                     </ul>
                  </nav>
               </div>
            </div>
            <div class="col-xxl-3 col-xl-3 col-lg-2 col-md-8 col-6">
               <div class="header__action d-flex align-items-center justify-content-end gap-1">
                  
                  {{-- Account --}}
                  @if(auth()->guard('customer')->check())
                     @php $c = auth('customer')->user(); @endphp
                     <a href="{{ route('account.dashboard') }}" class="header-icon-link" title="My Account">
                        <span class="header-avatar">{{ strtoupper(substr($c->first_name, 0, 1)) }}</span>
                     </a>
                  @else
                     <a href="{{ route('account.login') }}" class="header-icon-link" title="Account">
                        <i class="far fa-user"></i>
                     </a>
                  @endif

                  <a href="javascript:void(0);" onclick="openStoreSearch()" class="header-icon-link" title="Search">
                     <i class="far fa-search"></i>
                  </a>

                  <a href="javascript:void(0);" class="header-icon-link cart-toggle-btn" title="Cart">
                     <i class="far fa-shopping-bag"></i>
                     <span class="header-badge">0</span>
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