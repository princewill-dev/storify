<!DOCTYPE html>
<html lang="en">
<head>
    
	<!-- Title -->
	<title>@yield('title') - @yield('subtitle')</title>
	
	<!-- Meta -->
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="robots" content="index, no-follow">
	
	<!-- FAVICONS ICON -->
	<link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
	
	<!-- MOBILE SPECIFIC -->
	<meta name="viewport" content="width=device-width, initial-scale=1">
	
	<!-- Canonical URL -->
	<link rel="canonical" href="https://hexabox.dexignlab.com/xhtml/page-login.html">
	
	<!-- Plugins Stylesheet -->
	<link href="{{ asset('vendor_files/assets/vendor/@yaireo/tagify/dist/tagify.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/metismenu/dist/metisMenu.min.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}" rel="stylesheet">
	<link href="{{ asset('vendor_files/assets/vendor/bootstrap-select/dist/css/bootstrap-select.min.css') }}" rel="stylesheet">
	<link class="main-switcher" href="{{ asset('vendor_files/assets/css/switcher.css') }}" rel="stylesheet">
	
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
		
	<!-- Start - Authentication Wrapper -->
	@yield('content')
	<!-- End - Authentication Wrapper -->

	<!-- Start - Page Scripts -->
	<script src="{{ asset('vendor_files/assets/vendor/jquery/dist/jquery.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/metismenu/dist/metisMenu.min.js') }}"></script>
	<script src="{{ asset('vendor_files/assets/vendor/@yaireo/tagify/dist/tagify.js') }}"></script>
	
	<!-- Script For Custom JS -->
	<script src="{{ asset('vendor_files/assets/js/icnav-init.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('vendor_files/assets/js/custom.js') }}?v={{ time() }}"></script>
	
	<!-- Script For demo Styleswitcher -->
	<!-- <script src="{{ asset('vendor_files/assets/js/switcher/styleSwitcher.js') }}?v={{ time() }}"></script>
	<script src="{{ asset('vendor_files/assets/js/switcher/demo.js') }}?v={{ time() }}"></script> -->

</body>
</html>