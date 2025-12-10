<!doctype html>
<html class="no-js" lang="zxx">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Markit - Digital Marketplace HTML5 Template </title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Place favicon.ico in the root directory -->
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.png">
    <!-- CSS here -->
    <link rel="stylesheet" href="assets/css/preloader.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/slick.css">
    <link rel="stylesheet" href="assets/css/meanmenu.css">
    <link rel="stylesheet" href="assets/css/owl.carousel.min.css">
    <link rel="stylesheet" href="assets/css/animate.min.css">
    <link rel="stylesheet" href="assets/css/backToTop.css">
    <link rel="stylesheet" href="assets/css/jquery.fancybox.min.css">
    <link rel="stylesheet" href="assets/css/fontAwesome5Pro.css">
    <link rel="stylesheet" href="assets/css/elegantFont.css">
    <link rel="stylesheet" href="assets/css/imagetooltip.min.css">
    <link rel="stylesheet" href="assets/css/default.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>


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
    <header>
        <div class="header__area header__shadow-2 " id="header-sticky" style="background-color: #000;">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-4 col-6">
                        <div class="logo">
                            <a href="index.html">
                                <img src="assets/img/logo/logo-white.png" alt="logo">
                            </a>
                        </div>
                    </div>
                    <div class="col-xxl-7 col-xl-7 col-lg-8 d-none d-lg-block">
                        <div class="main-menu d-flex justify-content-end">
                            <nav id="mobile-menu">
                                <ul>
                                    <li class="active"><a href="index.html">Home</a></li>

                                    <li><a href="support.html">Support</a></li>

                                    <li class="has-dropdown">
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
    <!-- header area end -->

    <!-- sidebar area start -->
    <div class="sidebar__area">
        <div class="sidebar__wrapper">
            <div class="sidebar__close">
                <button class="sidebar__close-btn" id="sidebar__close-btn">
                    <span><i class="fal fa-times"></i></span>
                    <span>close</span>
                </button>
            </div>
            <div class="sidebar__content">
                <div class="logo mb-40">
                    <a href="index.html">
                        <img src="assets/img/logo/logo-white.png" alt="logo">
                    </a>
                </div>
                <div class="mobile-menu"></div>
            </div>
        </div>
    </div>
    <!-- sidebar area end -->
    <div class="body-overlay"></div>
    <!-- sidebar area end -->

    <main>

        <section class="product__area pt-105 pb-110 grey-bg-2pb-115">
            <div class="container">
                <div class="row">

                    <div class="col"></div>

                    <div class="col-md-6">

                        <div class="product__details-sidebar ml-30">
                            <div class="product__proprietor white-bg mb-30">
                                
                                <div class="product__proprietor-body fix">
                                    <div class="text-center">
                                        <h5><a href="#">Transaction: <span id="transaction_id"></span></a></h5>
                                        <h6>Status: <span id="status"></span></h6>
                                        <hr>
                                        <a href="./" class="m-btn m-btn-2 w-100 mb-20"> Home </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    
                        <script>
                            // Function to parse URL parameters
                            function getUrlParameter(name) {
                                name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
                                var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
                                var results = regex.exec(location.search);
                                return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
                            }
                    
                            // Get transaction_id and status from the URL
                            var transactionId = getUrlParameter('transaction_id');
                            var status = getUrlParameter('status');
                    
                            // Set the inner text of the HTML elements
                            document.getElementById('transaction_id').innerText = transactionId;
                            document.getElementById('status').innerText = status;
                        </script>

                    </div>

                    <div class="col"></div>

                </div>
            </div>
        </section>

    </main>

    <!-- footer area start -->
    <footer>
        <div class="footer__area footer-bg-2">
            <div class="footer__top pt-90 pb-50">
                <div class="container">
                    <div class="row">
                        <div class="col-xxl-3 col-xl-3 col-lg-3 col-md-4 col-sm-6">
                            <div class="footer__widget mb-40 wow fadeInUp" data-wow-delay=".3s">
                                <div class="footer__widget-head mb-35">
                                    <a href="index.html">
                                        <img src="assets/img/logo/logo.png" alt="">
                                    </a>
                                </div>
                                <div class="footer__widget-content">
                                    <div class="footer__social mb-30">
                                        <h4>Follow our Socials</h4>
                                        <ul>
                                            <li><a href="#" class="fb"><i class="fab fa-facebook-f"></i></a></li>
                                            <li><a href="#" class="tw"><i class="fab fa-twitter"></i></a></li>
                                            <li><a href="#" class="pin"><i class="fab fa-pinterest-p"></i></a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-4 col-sm-6">
                            <div class="footer__widget mb-40 wow fadeInUp" data-wow-delay=".5s">
                                <div class="footer__widget-head">
                                    <h4 class="footer__widget-title footer__widget-title-2">Products</h4>
                                </div>
                                <div class="footer__widget-content">
                                    <div class="footer__link footer__link-2">
                                        <ul>
                                            <li><a href="#">Tutor LMS </a></li>
                                            <li><a href="#">WP Mega Menu </a></li>
                                            <li><a href="#">WP Page Builder </a></li>
                                            <li><a href="#">Themes</a></li>
                                            <li><a href="#">Interactions</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-3 col-xl-3 col-lg-3 col-md-4 col-sm-6">
                            <div class="footer__widget mb-40 wow fadeInUp footer__widget-pl-70" data-wow-delay=".7s">
                                <div class="footer__widget-head">
                                    <h4 class="footer__widget-title footer__widget-title-2">Resources</h4>
                                </div>
                                <div class="footer__widget-content">
                                    <div class="footer__link footer__link-2">
                                        <ul>
                                            <li><a href="#">Promotion </a></li>
                                            <li><a href="#">Submit Content</a></li>
                                            <li><a href="#">Resources</a></li>
                                            <li><a href="#">Design System</a></li>
                                            <li><a href="#">Expert</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-4 col-xl-3 col-lg-3 col-md-5 col-sm-6">
                            <div class="footer__widget mb-40 wow fadeInUp footer__widget-sub-pl-70"
                                data-wow-delay=".7s">
                                <div class="footer__widget-head">
                                    <h4 class="footer__widget-title footer__widget-title-2">Newsletter</h4>
                                </div>
                                <div class="footer__widget-content">
                                    <div class="footer__subscribe">
                                        <p>Subscribe to recieve a monthly email on the latest news!</p>
                                        <div class="footer__subscribe-input">
                                            <form action="#">
                                                <input type="email" placeholder="Email">
                                                <button type="submit" class="m-btn">Subscribe!</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="footer__bottom">
                <div class="container">
                    <div class="footer__bottom-inner footer__bottom-inner-2">
                        <div class="row">
                            <div class="col-xxl-6 col-xl-6 col-md-6">
                                <div class="footer__copyright footer__copyright-2 wow fadeInUp" data-wow-delay=".5s">
                                    <p>Copyright © 2021 All Rights Reserved, Design by <a href="#">Theme Pure</a></p>
                                </div>
                            </div>
                            <div class="col-xxl-6 col-xl-6 col-md-6">
                                <div class="footer__bottom-link footer__bottom-link-2 wow fadeInUp text-md-end"
                                    data-wow-delay=".8s">
                                    <ul>
                                        <li><a href="#">Licence</a></li>
                                        <li><a href="#">Privacy Policy </a></li>
                                        <li><a href="#">Affiliate Notice</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <!-- footer area end -->

    <!-- JS here -->
    <script src="assets/js/vendor/jquery-3.5.1.min.js"></script>
    <script src="assets/js/vendor/waypoints.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/jquery.meanmenu.js"></script>
    <script src="assets/js/slick.min.js"></script>
    <script src="assets/js/jquery.fancybox.min.js"></script>
    <script src="assets/js/isotope.pkgd.min.js"></script>
    <script src="assets/js/parallax.min.js"></script>
    <script src="assets/js/owl.carousel.min.js"></script>
    <script src="assets/js/backToTop.js"></script>
    <script src="assets/js/jquery.counterup.min.js"></script>
    <script src="assets/js/ajax-form.js"></script>
    <script src="assets/js/wow.min.js"></script>
    <script src="assets/js/imagetooltip.min.js"></script>
    <script src="assets/js/imagesloaded.pkgd.min.js"></script>
    <script src="assets/js/main.js"></script>
</body>

</html>