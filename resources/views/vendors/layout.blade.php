<!DOCTYPE html>
<html lang="en">
<head>

	<!-- Title -->
	<title>Vendor - @yield('subtitle')</title>
	
	<!-- Meta -->
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="author" content="dexignlabs">
	<meta name="robots" content="index, no-follow">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	
	<!-- FAVICONS ICON -->
	<link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
	
	<!-- MOBILE SPECIFIC -->
	<meta name="viewport" content="width=device-width, initial-scale=1">
	
	<!-- Plugins Stylesheet -->
	<link href="{{ asset('vendor_files/assets/vendor/@yaireo/tagify/dist/tagify.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/metismenu/dist/metisMenu.min.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/bootstrap-select/dist/css/bootstrap-select.min.css') }}" rel="stylesheet">
	<link class="main-switcher" href="{{ asset('vendor_files/assets/css/switcher.css') }}" rel="stylesheet">
	
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap/dist/css/jsvectormap.min.css" >
	<link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/swiper/swiper-bundle.min.css') }}" >
	
	<!-- Start - Style CSS -->
	<link class="main-plugins" href="{{ asset('vendor_files/assets/css/plugins.css') }}" rel="stylesheet">
	<link class="main-css" href="{{ asset('vendor_files/assets/css/style.css') }}" rel="stylesheet">
	<!-- End - Style CSS -->
	
</head>
<body>

	<!-- Start - Preloader -->
	<div class="ic_preloader" id="ic_preloader">
		<div class="spinner">
			<div></div>
			<div></div>
			<div></div>
			<div></div>
			<div></div>
			<div></div>
		</div>
	</div>
	<!-- End - Preloader -->
		
    <!-- Start - Main Wrapper -->
	<div id="main-wrapper">
		
		<!-- Start - Nav Header -->
		@include('vendors.components.header')
		<!-- End - Nav Header -->
		
		<!-- Start - Sidebar Navigation -->
		@include('vendors.components.sidebar')
		<!-- End - Sidebar Navigation -->
		
		<!-- Start - Content Body -->
        <main class="content-body">
			
			<!-- Start - Page Title & Breadcrumb -->
			<div class="page-title">
					<nav aria-label="breadcrumb">
					<ol class="breadcrumb">
						<li class="breadcrumb-item">
							<a href="{{ route('admin.dashboard') }}">Dashboard</a>
						</li>
						<li class="breadcrumb-item active d-flex align-items-center" aria-current="page">
							@yield('subtitle')
						</li>
					</ol>
				</nav>
			</div>
			<!-- End - Page Title & Breadcrumb -->
			
			<div class="container-fluid">

				@yield('content')
			
			</div>
			
		</main>
		<!-- End - Content Body -->
		
		<!-- Start - Footer -->
		@include('vendors.components.footer')
		<!-- End - Footer -->
		
	</div>
	<!-- End - Main Wrapper -->

    {{-- Global Toasts (top-right) --}}
    @php($flashSuccess = session('success'))
    @php($flashError = session('error'))
    @if($flashSuccess || $flashError)
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1200;">
        @if($flashSuccess)
        <div class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    {{ $flashSuccess }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
        @endif
        @if($flashError)
        <div class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    {{ $flashError }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
        @endif
    </div>
    @endif

	<!-- Start - Page Scripts -->
	<script src="{{ asset('vendor_files/assets/vendor/jquery/dist/jquery.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/metismenu/dist/metisMenu.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/@yaireo/tagify/dist/tagify.js') }}"></script>
	
	<!-- Script For Swiper -->
	<script src="{{ asset('vendor_files/assets/vendor/swiper/swiper-bundle.min.js') }}"></script>
	
	<!-- Script For apexchart -->
	<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
	
	<!-- Script For Jsvectormap -->
    <script src="https://cdn.jsdelivr.net/npm/jsvectormap"></script>
	<script src="https://cdn.jsdelivr.net/npm/jsvectormap/dist/maps/world.js"></script>

	<!-- Script For Dashboard -->
	<script src="{{ asset('vendor_files/assets/js/dashboard/dashboard.js') }}"></script>
	
	<!-- Script For Multiple Languages -->
	<script src="{{ asset('vendor_files/assets/vendor/i18n/i18n.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/js/translator.js') }}"></script>
	
	<!-- Script For Custom JS -->
	<script src="{{ asset('vendor_files/assets/js/custom.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/js/icnav-init.js') }}"></script>
	
	<!-- Script For demo Styleswitcher -->
    <!-- <script src="{{ asset('vendor_files/assets/js/switcher/styleSwitcher.js') }}"></script> -->
	<script src="{{ asset('vendor_files/assets/js/switcher/demo.js') }}"></script>

    @stack('scripts')

    @if(($flashSuccess ?? false) || ($flashError ?? false))
    <script>
      (function(){
        var container = document.querySelector('.toast-container');
        if (!container) return;
        var toasts = container.querySelectorAll('.toast');
        toasts.forEach(function(el){
          try { new bootstrap.Toast(el, { autohide: true, delay: 5000 }).show(); } catch(e) {}
        });
      })();
    </script>
    @endif

	</body>
</html>