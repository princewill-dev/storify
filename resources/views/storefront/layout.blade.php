<!doctype html>
<html class="no-js" lang="zxx">
   <head>
      <meta charset="utf-8">
      <meta http-equiv="x-ua-compatible" content="ie=edge">
      <title>{{ $company->name ?? 'Storify' }} | @yield('title')</title>
      <meta name="description" content="">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <!-- Place favicon.ico in the root directory -->
      @php
          $assetBaseUrl = ""; // Use relative paths to avoid CORS issues on subdomains
      @endphp
      <link rel="shortcut icon" type="image/x-icon" href="{{ $assetBaseUrl }}/storefront/assets/img/favicon.png">
      <!-- CSS here -->
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/preloader.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/bootstrap.min.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/slick.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/meanmenu.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/owl.carousel.min.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/animate.min.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/backToTop.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/jquery.fancybox.min.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/fontAwesome5Pro.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/elegantFont.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/imagetooltip.min.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/default.css') }}">
      <link rel="stylesheet" href="{{ asset('storefront/assets/css/style.css') }}">
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
      <div class="progress-wrap">
         <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102">
            <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" />
         </svg>
      </div>
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

      <!-- footer area start -->
      @include('storefront.components.footer')
      <!-- footer area end -->

      <!-- JS here -->
      <!-- JS here -->
      <script src="{{ asset('storefront/assets/js/vendor/jquery-3.5.1.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/vendor/waypoints.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/bootstrap.bundle.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/jquery.meanmenu.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/slick.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/jquery.fancybox.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/isotope.pkgd.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/parallax.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/owl.carousel.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/backToTop.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/jquery.counterup.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/ajax-form.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/wow.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/imagetooltip.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/imagesloaded.pkgd.min.js') }}"></script>
      <script src="{{ asset('storefront/assets/js/main.js') }}"></script>
      @include('storefront.components.cart-scripts')
   </body>
</html>

