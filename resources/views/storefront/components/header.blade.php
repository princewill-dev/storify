<header>
   <div class="header__area header__shadow-2 " id="header-sticky">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-4 col-6">
               <div class="logo">
                  <a href="{{ store_url($store->slug) }}">
                     @if($store->logo_path)
                        <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}" style="max-height: 50px;">
                     @else
                        <img src="{{ asset('Storefront/assets/img/logo/logo-white.png') }}" alt="{{ $store->name ?? 'Store' }}">
                     @endif
                  </a>
               </div>
            </div>
            <div class="col-xxl-7 col-xl-7 col-lg-8 d-none d-lg-block">
               <div class="main-menu d-flex justify-content-end">
                  <nav id="mobile-menu">
                     <ul>
                        <li class="active"><a href="{{ store_url($store->slug) }}">Home</a></li>

                        <li><a href="support.html">Support</a></li>

                        <li  class="has-dropdown">
                           <a href="product.html">pages</a>

                           <ul class="submenu">
                              <li><a href="about.html">About</a></li>
                              <li><a href="documentation.html">Documentation</a></li>
                              <li><a href="pricing.html">Pricing</a></li>
                              <li><a href="sign-up.html">Sign Up</a></li>
                              <li><a href="sign-in.html">Log In</a></li>
                           </ul>
                        </li>

                        <li class="has-dropdown">
                           <a href="blog.html">Blog</a>

                           <ul class="submenu">
                              <li><a href="blog.html">Blog</a></li>
                              <li><a href="blog-details.html">Blog Details</a></li>
                           </ul>
                        </li>
                        
                        <li><a href="contact.html">Contact</a></li>

                        <li>
                           <a href="javascript:void(0);" class="cart-toggle-btn">
                           <i class="far fa-shopping-cart"></i>
                           <span>0</span>
                           </a>
                        </li>
                     </ul>
                  </nav>
               </div>
            </div>
            <div class="col-xxl-3 col-xl-3 col-lg-2 col-md-8 col-6">
               <div class="header__action d-flex align-items-center justify-content-end">
                  <!-- <div class="header__login header__login-2 d-none d-sm-block">
                     <a href="sign-in.html"><i class="far fa-unlock"></i> Log In</a>
                  </div>
                  <div class="header__btn d-none d-xl-block">
                     <a href="contact.html" class="m-btn m-btn-2">get started</a>
                  </div> -->
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