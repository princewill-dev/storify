<header id="header" class="tra-menu navbar-dark light-hero-header white-scroll">
    <div class="header-wrapper">


        <!-- MOBILE HEADER -->
        <div class="wsmobileheader clearfix">	  	
            <span class="smllogo"><img src="{{ $company->logo }}" alt="mobile-logo"></span>
            <a id="wsnavtoggle" class="wsanimated-arrow"><span></span></a>	
            </div>


            <!-- NAVIGATION MENU -->
            <div class="wsmainfull menu clearfix">
            <div class="wsmainwp clearfix">


                <!-- HEADER BLACK LOGO -->
                <div class="desktoplogo">
                    <a href="{{ route('home.index') }}" class="logo-black"><img src="{{ $company->logo }}" alt="logo"></a>
                </div>


                <!-- HEADER WHITE LOGO -->
                <div class="desktoplogo">
                    <a href="{{ route('home.index') }}" class="logo-white"><img src="{{ $company->logo }}" alt="logo"></a>
                </div>


                <!-- MAIN MENU -->
                    <nav class="wsmenu clearfix">
                    <ul class="wsmenu-list nav-theme">


                        <!-- SIMPLE NAVIGATION LINK -->
                        <li class="nl-simple" aria-haspopup="true"><a href="about-us.html" class="h-link">About</a></li>
                        
                        <li class="nl-simple" aria-haspopup="true"><a href="./#features" class="h-link">Our Services</a></li>

                        <li class="nl-simple" aria-haspopup="true"><a href="projects.html" class="h-link">Checkout Stores</a></li>
                        
                        <li class="nl-simple" aria-haspopup="true"><a href="./#features" class="h-link">Support</a></li>

                        <li class="nl-simple" aria-haspopup="true"><a href="{{ route('vendor.auth.login') }}" class="h-link">Login</a></li>


                        <!-- SIGN UP BUTTON -->
                        <li class="nl-simple" aria-haspopup="true">
                            <a href="{{ route('vendor.auth.register') }}" class="btn r-04 btn--theme hover--theme last-link">Create Store</a>
                        </li> 

                    </ul>
                </nav>	<!-- END MAIN MENU -->
            </div>
        </div>	<!-- END NAVIGATION MENU -->
    </div><!-- End header-wrapper -->
</header>