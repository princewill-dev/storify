<!DOCTYPE html>
<html lang="en">
<head>
	
	<!-- Meta -->
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="keywords" content="">
	<meta name="robots" content="">
	
    @include('home.components.seo', [
      'seo' => [
        'title' => $company->og_title,
        'description' => $company->og_description,
        'image' => $company->og_image,
        'url' => $company->og_url,
        'type' => $company->og_type,
        'twitter_card' => 'summary_large_image',
      ]
    ])
	
	<meta name="format-detection" content="telephone=no">
	
	<!-- FAVICONS ICON -->
	<link rel="icon" type="image/x-icon" href="{{ $company->favicon }}">
	
	<!-- PAGE TITLE HERE -->
	<title>{{ $company->name }} | @yield('title')</title>
	
	<!-- MOBILE SPECIFIC -->
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	
	<!-- STYLESHEETS -->
	<link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/bootstrap-select/dist/css/bootstrap-select.min.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/icons/fontawesome/css/all.min.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/icons/themify/themify-icons.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/icons/flaticon/flaticon_mooncart.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/swiper/swiper-bundle.min.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/nouislider/nouislider.min.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/animate/animate.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/lightgallery/dist/css/lightgallery.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/lightgallery/dist/css/lg-thumbnail.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('home/vendor/lightgallery/dist/css/lg-zoom.css') }}">
	<link rel="stylesheet" type="text/css" href="{{ asset('home/css/style.css') }}">
	
	<!-- GOOGLE FONTS-->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Roboto:wght@100;300;400;500;700;900&display=swap" rel="stylesheet">

    @stack('scripts')

</head>	
<body>

    <div class="page-wraper" id="scroll-container">

        <div id="loading-area" class="preloader-wrapper-1">
            <div>
                <span class="loader-2"></span>
                <img src="{{ $company->logo }}" alt="/">
                <span class="loader"></span>
            </div>
        </div>
        
        @include('home.components.header')
        <!-- Header End -->
        
        <div class="page-content bg-white">
        
            @yield('content')
            
        </div>
        
        <!-- Footer -->
        @include('home.components.footer')
        <!-- Footer End -->
        
        <button class="scroltop" type="button"><i class="fas fa-arrow-up"></i></button>
        
        <!-- Quick Modal Start -->
        @include('home.components.view_product_modal')
        <!-- Quick Modal End -->
        
        <!-- Greeting Modal Start (Homepage Only) -->
        @if(($company->greeting_modal_enabled ?? false) && request()->is('/'))
            @include('home.components.greeting-modal', [
                'company' => $company,
                'services' => $services ?? collect()
            ])
        @endif
        <!-- Greeting Modal End -->
        
    </div>
    <!-- JAVASCRIPT FILES ========================================= -->
    <script src="{{ asset('home/js/jquery.min.js') }}"></script><!-- JQUERY MIN JS -->
    <script src="{{ asset('home/vendor/wow/wow.min.js') }}"></script><!-- WOW JS -->
    <script src="{{ asset('home/vendor/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script><!-- BOOTSTRAP MIN JS -->
    <script src="{{ asset('home/vendor/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script><!-- BOOTSTRAP SELECT MIN JS -->
    <script src="{{ asset('home/vendor/bootstrap-touchspin/bootstrap-touchspin.js') }}"></script><!-- BOOTSTRAP TOUCHSPIN JS -->
    <script src="{{ asset('home/vendor/counter/waypoints-min.js') }}"></script><!-- WAYPOINTS JS -->
    <script src="{{ asset('home/vendor/counter/counterup.min.js') }}"></script><!-- COUNTERUP JS -->
    <script src="{{ asset('home/vendor/swiper/swiper-bundle.min.js') }}"></script><!-- SWIPER JS -->
    <script src="{{ asset('home/vendor/imagesloaded/imagesloaded.js') }}"></script><!-- IMAGESLOADED-->
    <script src="{{ asset('home/vendor/imagesloaded/imagesloaded.js') }}"></script><!-- IMAGESLOADED-->
    <script src="{{ asset('home/vendor/masonry/masonry-4.2.2.js') }}"></script><!-- MASONRY -->
    <script src="{{ asset('home/vendor/masonry/isotope.pkgd.min.js') }}"></script><!-- ISOTOPE -->
    <script src="{{ asset('home/vendor/countdown/jquery.countdown.js') }}"></script><!-- COUNTDOWN FUCTIONS  -->
    <script src="{{ asset('home/vendor/wnumb/wNumb.js') }}"></script><!-- WNUMB -->
    <script src="{{ asset('home/vendor/nouislider/nouislider.min.js') }}"></script><!-- NOUSLIDER MIN JS-->
    <script src="{{ asset('home/js/dz.carousel.js') }}"></script><!-- DZ CAROUSEL JS -->
    <script src="{{ asset('home/vendor/lightgallery/dist/lightgallery.min.js') }}"></script>
    <script src="{{ asset('home/vendor/lightgallery/dist/plugins/thumbnail/lg-thumbnail.min.js') }}"></script>
    <script src="{{ asset('home/vendor/lightgallery/dist/plugins/zoom/lg-zoom.min.js') }}"></script>
    <script src="{{ asset('home/js/dz.ajax.js') }}"></script><!-- AJAX -->
    <script src="{{ asset('home/js/custom.js') }}"></script><!-- CUSTOM JS -->
</body>
</html>