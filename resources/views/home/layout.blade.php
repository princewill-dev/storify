<!doctype html>
<!-- Martex - Software, App, SaaS & Startup Landing Pages Pack design by DSAThemes (http://www.dsathemes.com) -->
<!--[if lt IE 7 ]><html class="ie ie6" lang="en"> <![endif]-->
<!--[if IE 7 ]><html class="ie ie7" lang="en"> <![endif]-->
<!--[if IE 8 ]><html class="ie ie8" lang="en"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!-->
<html lang="en">

	<head>

		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="author" content="Digiswitch.tech">	
		<meta name="description" content="We help traditional businesses thrive in the digital age through customized web solutions. Our experienced team provides website development, digital marketing, automation, and integrated payment solutions to digitize and modernize businesses">
		<meta name="keywords" content="web development company, website development, web design, digital marketing, online marketing, SEO, social media marketing, conversion optimization, ecommerce website, online payments, POS integration, business digitization, automate business processes, modernize small business, traditional to digital business, bringing businesses online, lagos based web development company, Digiswitch, Digiswitch.tech">	
		<meta name="viewport" content="width=device-width, initial-scale=1">
				
		<!-- SITE TITLE -->
		<title>{{ $company->name }} | @yield('title')</title>
							
		<!-- FAVICON AND TOUCH ICONS -->
		<link rel="shortcut icon" href="{{ asset('home/images/favicon.ico') }}" type="image/x-icon">
		<link rel="icon" href="{{ asset('home/images/favicon.ico') }}" type="image/x-icon">
		<link rel="apple-touch-icon" sizes="152x152" href="{{ asset('home/images/apple-touch-icon-152x152.png') }}">
		<link rel="apple-touch-icon" sizes="120x120" href="{{ asset('home/images/apple-touch-icon-120x120.png') }}">
		<link rel="apple-touch-icon" sizes="76x76" href="{{ asset('home/images/apple-touch-icon-76x76.png') }}">
		<link rel="apple-touch-icon" href="{{ asset('home/images/apple-touch-icon.png') }}">
		<link rel="icon" href="{{ asset('home/images/apple-touch-icon.png') }}" type="image/x-icon">

		<!-- GOOGLE FONTS -->
		<link href="https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
		
		<!-- BOOTSTRAP CSS -->
		<link href="css/bootstrap.min.css" rel="stylesheet">
				
		<!-- FONT ICONS -->
		<link href="css/flaticon.css" rel="stylesheet">

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
		<script src="{{ asset('home/js/request-form.js' }}"></script>	
		<script src="{{ asset('home/js/jquery.validate.min.js') }}"></script>
		<script src="{{ asset('home/js/jquery.ajaxchimp.min.js') }}"></script>	
		<script src="{{ asset('home/js/popper.min.js') }}"></script>
		<script src="{{ asset('home/js/lunar.js') }}"></script>
		<script src="{{ asset('home/js/wow.js') }}"></script>
				
		<!-- Custom Script -->		
		<script src="{{ asset('home/js/custom.js') }}"></script>

	</body>
</html>