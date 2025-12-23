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
							<a href="{{ route('vendor.dashboard') }}">Dashboard</a>
						</li>
						<li class="breadcrumb-item active d-flex align-items-center" aria-current="page">
							@yield('subtitle')
							@if(Route::is('vendor.dashboard'))
							<div class="ms-3 dropdown">
								@php
									$vendor = auth('vendor')->user();
									$activeStore = $vendor->stores->find(session('active_store_id')) ?? $vendor->stores->first();
								@endphp
								<button class="btn btn-light btn-sm dropdown-toggle fw-bold py-1 px-3 rounded-pill shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.85rem; border: 1px solid #e0e0e0;">
									<i class="fi fi-rr-shop me-2 text-primary"></i>
									{{ $activeStore->name ?? 'Select Store' }}
								</button>
								<ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="border-radius: 12px; min-width: 220px;">
									<li class="dropdown-header text-uppercase fs-xs fw-black px-3 mt-1" style="color: #1a1a1a; letter-spacing: 0.5px;">My Stores</li>
									@foreach($vendor->stores as $store)
									<li>
										<a class="dropdown-item d-flex align-items-center py-2 px-3 {{ session('active_store_id') == $store->id ? 'bg-primary-light' : '' }}" href="javascript:void(0)" onclick="event.preventDefault(); document.getElementById('switch-store-{{ $store->id }}').submit();">
											<div class="avatar avatar-xs bg-light rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; border: 1px solid #eee;">
												<i class="fi fi-rr-shop fs-xs text-dark"></i>
											</div>
											<span class="fw-bold text-dark fs-base">{{ $store->name }}</span>
											@if(session('active_store_id') == $store->id)
												<i class="fi fi-rr-check-circle ms-auto text-primary fs-sm"></i>
											@endif
										</a>
										<form id="switch-store-{{ $store->id }}" action="{{ route('vendor.stores.switch') }}" method="POST" style="display: none;">
											@csrf
											<input type="hidden" name="store_id" value="{{ $store->id }}">
										</form>
									</li>
									@endforeach
									
									@if($vendor->canCreateMoreStores())
									<li><hr class="dropdown-divider mx-2"></li>
									<li>
										<a class="dropdown-item d-flex align-items-center py-2 px-3" href="{{ route('vendor.stores.create', ['vendor' => $vendor]) }}">
											<div class="avatar avatar-xs bg-primary-light text-primary rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
												<i class="fi fi-rr-plus fs-xs"></i>
											</div>
											<span class="fw-bold text-primary fs-base">Add New Store</span>
										</a>
									</li>
									@endif
								</ul>
							</div>
							@endif
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