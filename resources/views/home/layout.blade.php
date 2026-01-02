<!doctype html>
<html lang="en">

	<head>

		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">

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

		<meta name="viewport" content="width=device-width, initial-scale=1">
				
		<!-- SITE TITLE -->
		<title>{{ $company->name }} | @yield('title')</title>
							
		<!-- FAVICON AND TOUCH ICONS -->
		<link rel="shortcut icon" href="{{ $company->favicon }}" type="image/x-icon">
		<link rel="icon" href="{{ $company->favicon }}" type="image/x-icon">
		<link rel="apple-touch-icon" sizes="152x152" href="{{ $company->favicon }}">
		<link rel="apple-touch-icon" sizes="120x120" href="{{ $company->favicon }}">
		<link rel="apple-touch-icon" sizes="76x76" href="{{ $company->favicon }}">
		<link rel="apple-touch-icon" href="{{ $company->favicon }}">
		<link rel="icon" href="{{ $company->favicon }}" type="image/x-icon">

		<!-- GOOGLE FONTS -->
		<link href="https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
		
		<!-- BOOTSTRAP CSS -->
		<link href="{{ asset('home/css/bootstrap.min.css') }}" rel="stylesheet">
				
		<!-- FONT ICONS -->
		<link href="{{ asset('home/css/flaticon.css') }}" rel="stylesheet">

		<!-- PLUGINS STYLESHEET -->
		<link href="{{ asset('home/css/menu.css') }}" rel="stylesheet">	
		<link id="effect" href="{{ asset('home/css/dropdown-effects/fade-down.css') }}" media="all" rel="stylesheet">
		<link href="{{ asset('home/css/magnific-popup.css') }}" rel="stylesheet">	
		<link href="{{ asset('home/css/owl.carousel.min.css') }}" rel="stylesheet">
		<link href="{{ asset('home/css/owl.theme.default.min.css') }}" rel="stylesheet">
		<link href="{{ asset('home/css/lunar.css') }}" rel="stylesheet">

		<!-- ON SCROLL ANIMATION -->
		<link href="{{ asset('home/css/animate.css') }}" rel="stylesheet">

		<!-- TEMPLATE CSS -->
		<link href="{{ asset('home/css/crocus-theme.css') }}" rel="stylesheet">
		
		<!-- RESPONSIVE CSS -->
		<link href="{{ asset('home/css/responsive.css') }}" rel="stylesheet">

		@stack('styles')
		@stack('styles')

	</head>

	<body class="theme--dark">

		<!-- PRELOADER SPINNER
		============================================= -->	
		<div id="loading" class="loading--theme">
			<div id="loading-center"><span class="loader"></span></div>
		</div>

		<!-- PAGE CONTENT
		============================================= -->	
		<div id="page" class="page font--jakarta">

			<!-- HEADER
			============================================= -->
			@include('home.components.header')
			<!-- END HEADER -->

			@yield('content')

			<!-- FOOTER-3
			============================================= -->
			@include('home.components.footer')
			<!-- END FOOTER-3 -->

		</div>
		<!-- END PAGE CONTENT -->

		<!-- EXTERNAL SCRIPTS
		============================================= -->	
		<script src="{{ asset('home/js/jquery-3.7.0.min.js') }}"></script>
		<script src="{{ asset('home/js/bootstrap.min.js') }}"></script>	
		<script src="{{ asset('home/js/modernizr.custom.js') }}"></script>
		<script src="{{ asset('home/js/jquery.easing.js') }}"></script>
		<script src="{{ asset('home/js/jquery.appear.js') }}"></script>
		<script src="{{ asset('home/js/menu.js') }}"></script>
		<script src="{{ asset('home/js/owl.carousel.min.js') }}"></script>
		<script src="{{ asset('home/js/pricing-toggle.js') }}"></script>
		<script src="{{ asset('home/js/jquery.magnific-popup.min.js') }}"></script>
		<script src="{{ asset('home/js/request-form.js') }}"></script>	
		<script src="{{ asset('home/js/jquery.validate.min.js') }}"></script>
		<script src="{{ asset('home/js/jquery.ajaxchimp.min.js') }}"></script>	
		<script src="{{ asset('home/js/popper.min.js') }}"></script>
		<script src="{{ asset('home/js/lunar.js') }}"></script>
		<script src="{{ asset('home/js/wow.js') }}"></script>
				
		<!-- Custom Script -->		
		<script src="{{ asset('home/js/custom.js') }}"></script>

		@stack('scripts')

	</body>
</html>