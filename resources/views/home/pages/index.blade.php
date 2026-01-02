@extends('home.layout')
@section('title', 'Home')

@section('content')

<!-- HERO-19 ============================================= -->	
<section id="hero-19" class="blur--purple gr--ghost hero-section">
	<div class="container text-center">


		<!-- HERO TEXT -->
		<div class="row justify-content-center">
			<div class="col-md-10 col-lg-9">
				<div class="hero-19-txt">
			
					<!-- Title -->
					<h2 class="s-56 w-700">Go global with a cool storefront</h2>

					<!-- Text -->
					<p class="p-xl">Transform your vision into a high-performance online store.</p>

					<!-- Buttons -->	
					<div class="btns-group">
						<a href="{{ route('vendor.auth.register') }}" class="btn r-04 btn--theme hover--theme" style="display: inline-flex; align-items: center; min-height: 52px;">Create Your Store</a>
					</div>

					<br>

					<center>
						<a href="{{ route('home.stores') }}">view stores</a>
					</center>
					
				</div>
			</div>
		</div>
		<!-- END HERO TEXT -->	

		<!-- BRANDS CAROUSEL -->
		<div id="brands-1" class="py-90">			
			<div class="row">
				<div class="col text-center">	
					<div class="owl-carousel brands-carousel-6">

										
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-1-white.png') }}" alt="brand-logo"></a>
						</div>

											
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-3-white.png') }}" alt="brand-logo"></a>
						</div>

											
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-4-white.png') }}" alt="brand-logo"></a>
						</div>

											
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-5-white.png') }}" alt="brand-logo"></a>
						</div>

											
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-6-white.png') }}" alt="brand-logo"></a>
						</div>

											
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-7-white.png') }}" alt="brand-logo"></a>
						</div>


						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-8-white.png') }}" alt="brand-logo"></a>
						</div>

													
						<!-- BRAND LOGO IMAGE -->
						<div class="brand-logo">
							<a href="#"><img class="img-fluid" src="{{ asset('home/images/brand-9-white.png') }}" alt="brand-logo"></a>
						</div>


					</div>
				</div>
			</div>  <!-- End row -->
		</div>	<!-- END BRANDS CAROUSEL -->


		


	</div>    <!-- End container --> 
</section>
<!-- END HERO-19 -->	

<!-- TEXT CONTENT
============================================= -->
<section id="lnk-1" class="pt-100 ct-02 content-section division">
	<div class="container">


		<!-- SECTION CONTENT (ROW) -->	
		<div class="row d-flex align-items-center">

			<!-- TEXT BLOCK -->	
			<div class="col-md-6">
				<div class="txt-block right-column wow fadeInLeft">

					<!-- Section ID -->	
					<span class="section-id">Strategies That Work</span>

					<!-- Title -->	
					<h2 class="s-46 w-700">Take The Big Digital Leap</h2>

					<!-- Text -->	
					<p>
						Transform your vision into a high-converting digital storefront that captivates customers and drives sales. We handle all the technical heavy lifting—from seamless user interfaces to secure payment processing—so you can focus on growing your brand and reaching new markets.
					</p>

					<!-- CONTENT BOX #1 -->
					<div class="cbox-1 ico-15">

						<div class="ico-wrap color--theme">
							<div class="cbox-1-ico"><span class="flaticon-check"></span></div>
						</div>

						<div class="cbox-1-txt">
							<p>High-Converting Storefront Design</p>
						</div>

					</div>

					<!-- CONTENT BOX #2 -->
					<div class="cbox-1 ico-15">

						<div class="ico-wrap color--theme">
							<div class="cbox-1-ico"><span class="flaticon-check"></span></div>
						</div>

						<div class="cbox-1-txt">
							<p>Secure & Integrated Payment Gateways</p>
						</div>

					</div>

					<!-- CONTENT BOX #3 -->
					<div class="cbox-1 ico-15">

						<div class="ico-wrap color--theme">
							<div class="cbox-1-ico"><span class="flaticon-check"></span></div>
						</div>

						<div class="cbox-1-txt">
							<p class="mb-0">Mobile-First Responsive Experience</p>
						</div>

					</div>

				</div>
			</div>	<!-- END TEXT BLOCK -->	
			
			
			<!-- IMAGE BLOCK -->
			<div class="col-md-6">
				<div class="img-block left-column wow fadeInRight">
					<img class="img-fluid" src="{{ asset('home/images/img-11.png') }}" alt="content-image">
				</div>
			</div>


		</div>	<!-- END SECTION CONTENT (ROW) -->	


	</div>	   <!-- End container -->
</section>	<!-- END TEXT CONTENT -->

<!-- FEATURES-2
============================================= -->
<section id="features" class="py-100 features-section division">
	<div class="container">


		<!-- SECTION TITLE -->	
		<div class="row justify-content-center">	
			<div class="col-md-10 col-lg-9">
				<div class="section-title mb-80">	

					<!-- Title -->	
					<h2 class="s-50 w-700">Everything Your Store Needs</h2>	

					<!-- Text -->	
					<p class="s-21 color--grey">Launch, manage, and scale your online store with powerful built-in features.</p>
						
				</div>	
			</div>
		</div>


		<!-- FEATURES-2 WRAPPER -->
		<div class="fbox-wrapper text-center">
			<div class="row row-cols-1 row-cols-md-3 rows-2">


				<!-- FEATURE BOX #1 -->
				<div class="col">
					<div class="fbox-2 fb-1 wow fadeInUp">

						<!-- Image -->
						<div class="fbox-img gr--whitesmoke h-170">
							<img class="img-fluid" src="{{ asset('home/images/f_04_dark.png') }}" alt="feature-image">
						</div>

						<!-- Text -->
						<div class="fbox-txt">
							<h6 class="s-22 w-700">Custom Branded Storefront</h6>
							<p>Get your own stunning online store with a custom subdomain, personalized branding, and a professional look that builds customer trust.</p>
						</div>

					</div>
				</div>	<!-- END FEATURE BOX #1 -->	


				<!-- FEATURE BOX #2 -->
				<div class="col">
					<div class="fbox-2 fb-2 wow fadeInUp">

						<!-- Image -->
						<div class="fbox-img gr--whitesmoke h-170">
							<img class="img-fluid" src="{{ asset('home/images/f_09_dark.png') }}" alt="feature-image">
						</div>

						<!-- Text -->
						<div class="fbox-txt">
							<h6 class="s-22 w-700">Smart Inventory Management</h6>
							<p>Easily add products, track stock levels, and manage orders from a powerful dashboard. Automated alerts keep you informed when it's time to restock.</p>
						</div>

					</div>
				</div>	<!-- END FEATURE BOX #2 -->		


				<!-- FEATURE BOX #3 -->
				<div class="col">
					<div class="fbox-2 fb-3 wow fadeInUp">

						<!-- Image -->
						<div class="fbox-img gr--whitesmoke h-170">
							<img class="img-fluid" src="{{ asset('home/images/f_01_dark.png') }}" alt="feature-image">
						</div>

						<!-- Text -->
						<div class="fbox-txt">
							<h6 class="s-22 w-700">Global Payment Solutions</h6>
							<p>Accept payments worldwide with integrated support for cards, bank transfers, and mobile wallets. Secure checkout builds customer confidence.</p>
						</div>

					</div>
				</div>	<!-- END FEATURE BOX #3 -->	


			</div>  <!-- End row -->  
			
			
			
		</div>	<!-- END FEATURES-2 WRAPPER -->

		
	</div>
	<!-- End container -->
</section>
<!-- END FEATURES-2 -->

<!-- TEXT CONTENT
============================================= -->
<section class="pt-100 ct-01 content-section division">
	<div class="container">


		<!-- SECTION CONTENT (ROW) -->	
		<div class="row d-flex align-items-center">


			<!-- TEXT BLOCK -->	
			<div class="col-md-6 order-last order-md-2">
				<div class="txt-block left-column wow fadeInRight">

					<!-- Section ID -->	
					<span class="section-id">Business Intelligence</span>

					<!-- Title -->	
					<h2 class="s-46 w-700">Deep Insights That Drive Growth</h2>

					<p>Understand what's working and what's not with powerful analytics built into your Storify dashboard. Track sales performance, monitor customer behavior, and identify your best-selling products—all in real-time. Make data-driven decisions that boost revenue and keep your store ahead of the competition.</p>

				</div>
			</div>
			<!-- END TEXT BLOCK -->	


			<!-- IMAGE BLOCK -->
			<div class="col-md-6 order-first order-md-2">
				<div class="img-block right-column wow fadeInLeft">
					<img class="img-fluid" src="{{ asset('home/images/img-02.png') }}" alt="content-image">
				</div>
			</div>


		</div>	<!-- END SECTION CONTENT (ROW) -->
		
		<center>
			<br>
			<br>
			<img class="img-fluid" src="{{ asset('home/images/dashboard-pic.png') }}" alt="content-image">
		</center>
		
		

	</div>	   <!-- End container -->
	
	
</section>	<!-- END TEXT CONTENT -->
	
<section class="pt-100 ct-04 content-section division" id="about-us">
	<div class="container">


		<!-- SECTION CONTENT (ROW) -->	
		<div class="row d-flex align-items-center">


			<!-- TEXT BLOCK -->	
			<div class="col-md-6">
				<div class="txt-block left-column wow fadeInRight">


					<!-- CONTENT BOX #1 -->
					<div class="cbox-4">
						
						<!-- Icon & Title -->
						<div class="box-title">
							<h5 class="s-24 w-700">Why Build With Us?</h5>
						</div>

						<!-- Text -->
						<div class="cbox-4-txt">
							<p style="text-align: left;">At DigiSwitch. we don't just build websites - we craft digital experiences that help our clients succeed. Our focus is always on delivering truly world-class projects and top-notch services. We take pride in our work and hold ourselves to the highest standards when it comes to quality code and expert development. Our team stays on top of the latest technologies and industry best practices to ensure we build innovative solutions.</p>
						</div>
																																			
					</div>	<!-- END CONTENT BOX #1 -->	


				</div>
			</div>	<!-- END TEXT BLOCK -->		


			<!-- IMAGE BLOCK -->	
			<div class="col-md-6">
				<div class="img-block wow fadeInLeft">
					<img class="img-fluid" src="{{ asset('home/images/tablet-01.png') }}" alt="content-image">
				</div>	
			</div>


		</div>	<!-- END SECTION CONTENT (ROW) -->	


	</div>	   <!-- End container -->
</section>

<section id="projects" class="py-100 blog-section division" style="display: none;">
	<div class="container">


		<!-- SECTION TITLE -->	
		<div class="row justify-content-center">	
			<div class="col-md-10 col-lg-9">
				<div class="section-title mb-70">	

					<!-- Title -->	
					<h2 class="s-50 w-700">Our Works</h2>	

					<!-- Text -->	
					<p class="s-21 color--grey">Ligula risus auctor tempus magna feugiat lacinia.</p>
						
				</div>	
			</div>
		</div>


		<div class="row">


			<!-- BLOG POST #1 -->
			<div class="col-md-6 col-lg-4">
				<div id="bp-1-1" class="blog-post wow fadeInUp">	

					<!-- BLOG POST IMAGE -->
					<div class="blog-post-img mb-35">
						<img class="img-fluid r-16" src="{{ asset('home/images/blog/post-8-img.jpg') }}" alt="blog-post-image">
					</div>	

					<!-- BLOG POST TEXT -->
					<div class="blog-post-txt">

						<!-- Post Tag -->
						<span class="post-tag color--theme">Product News</span>	

						<!-- Post Link -->
						<h6 class="s-20 w-700">
							<a href="single-post.html">Aliqum mullam porta blandit: tempor sapien and gravida</a>
						</h6>

						<!-- Text -->
						<p>Egestas luctus vitae augue and ipsum ultrice quisque in cursus lacus feugiat congue 
							diam ultrice laoreet sagittis
						</p>

						<!-- Post Meta -->
						<div class="blog-post-meta mt-20">
							<ul class="post-meta-list ico-10">
								<li><p class="p-sm w-500">By Helen J.</p></li>
								<li class="meta-list-divider"><p><span class="flaticon-minus"></span></p></li>
								<li><p class="p-sm">Apr 28, 2023</p></li>
							</ul>
						</div>

					</div>	<!-- END BLOG POST TEXT -->

				</div>
			</div>	<!-- END BLOG POST #1 -->


			<!-- BLOG POST #2 -->
			<div class="col-md-6 col-lg-4">
				<div id="bp-1-2" class="blog-post wow fadeInUp">	

					<!-- BLOG POST IMAGE -->
					<div class="blog-post-img mb-35">
						<img class="img-fluid r-16" src="{{ asset('home/images/blog/post-2-img.jpg') }}" alt="blog-post-image">
					</div>	

					<!-- BLOG POST TEXT -->
					<div class="blog-post-txt">

						<!-- Post Tag -->
						<span class="post-tag color--green-400">Community</span>	

						<!-- Post Link -->
						<h6 class="s-20 w-700">
							<a href="single-post.html">Porttitor cursus fusce egestas CEO cursus at magna sapien 
								suscipit and egestas ipsum
							</a>
						</h6>

						<!-- Text -->
						<p>Aliqum mullam ipsum vitae and blandit vitae tempor sapien and donec lipsum</p>

						<!-- Post Meta -->
						<div class="blog-post-meta mt-20">
							<ul class="post-meta-list ico-10">
								<li><p class="p-sm w-500">By Martex Team</p></li>
								<li class="meta-list-divider"><p><span class="flaticon-minus"></span></p></li>
								<li><p class="p-sm">Apr 14, 2023</p></li>
							</ul>
						</div>

					</div>	<!-- END BLOG POST TEXT -->
					
				</div>
			</div>	<!-- END BLOG POST #2 -->


			<!-- BLOG POST #3 -->
			<div class="col-md-12 col-lg-4">
				<div id="bp-1-3" class="blog-post wow fadeInUp">	

					<!-- BLOG POST IMAGE -->
					<div class="blog-post-img mb-35">
						<img class="img-fluid r-16" src="{{ asset('home/images/blog/post-5-img.jpg') }}" alt="blog-post-image">
					</div>	

					<!-- BLOG POST TEXT -->
					<div class="blog-post-txt">

						<!-- Post Tag -->
						<span class="post-tag color--purple-400">Freelancer Tips</span>	

						<!-- Post Link -->
						<h6 class="s-20 w-700">
							<a href="single-post.html">Cubilia laoreet augue egestas and Martex magna impedit</a>
						</h6>

						<!-- Text -->
						<p>Luctus vitae egestas augue and ipsum ultrice quisque in cursus lacus feugiat egets 
							congue ultrice sagittis laoreet 
						</p>

						<!-- Post Meta -->
						<div class="blog-post-meta mt-20">
							<ul class="post-meta-list ico-10">
								<li><p class="p-sm w-500">By Miranda Green</p></li>
								<li class="meta-list-divider"><p><span class="flaticon-minus"></span></p></li>
								<li><p class="p-sm">Mar 27, 2023</p></li>
							</ul>
						</div>

					</div>	<!-- END BLOG POST TEXT -->
					
				</div>
			</div>	<!-- END BLOG POST #3 -->

			
		</div>    <!-- End row -->
		</div>    <!-- End container -->
		
		<br>
		
		<center>
			<a href="projects.php" class="btn r-04 btn--theme hover--theme">See More Projeccts</a>
		</center>
		
</section>

<!-- BANNER-13
============================================= -->
<section id="banner-13" class="pt-100 banner-section">
	<div class="container">


		<!-- BANNER-13 WRAPPER -->
		<div class="banner-13-wrapper bg--03 bg--scroll r-16 block-shadow">
			<div class="banner-overlay">
				<div class="row d-flex align-items-center">


					<!-- BANNER-5 TEXT -->
					<div class="col-md-7">
						<div class="banner-13-txt color--white">

							<!-- Title -->	
							<h2 class="s-46 w-700">Join the Digital Age today!</h2>

							<!-- Text -->
							<p class="p-lg">Let's take your business to the next level. We make it easy to get started online and handle all the technical work for you. </p>

							<!-- Button -->
							<a href="contact-us.html" class="btn r-04 btn--theme hover--tra-white" data-bs-toggle="modal" data-bs-target="#modal-3">Get a Free Consultation</a>

						</div>
					</div>	<!-- END BANNER-13 TEXT -->


					<!-- BANNER-13 IMAGE -->
					<div class="col-md-5">
						<div class="banner-13-img text-center">
							<img class="img-fluid" src="{{ asset('home/images/img-04.png') }}" alt="banner-image">
						</div>	
					</div>


				</div>   <!-- End row -->	
			</div>   <!-- End banner overlay -->	
		</div>    <!-- END BANNER-13 WRAPPER -->


	</div>     <!-- End container -->	
</section>	<!-- END BANNER-13 -->


<!-- DIVIDER LINE -->
<hr class="divider">

@endsection