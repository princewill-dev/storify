<section class="newsletter-wrapper style-1">
    <div class="container">
        <div class="subscride-inner">
            <div class="row style-1 justify-content-xl-between justify-content-lg-center align-items-center text-xl-start text-center">
                <div class="col-xl-6 col-lg-12 wow fadeInUp" data-wow-delay="0.1s">
                    <div class="d-flex align-items-center justify-content-center justify-content-xl-start mb-3 mb-xl-0 flex-column flex-xl-row">
                        <img class="me-4" src="{{ asset('home/images/svg/chat.svg') }}" alt="">
                        <div class="section-head mb-0">
                            <h3 class="title text-white">SUBSCRIBE TO OUR NEWSLETTER</h3>
                            <p class="sub-title text-white">Get latest news, offers and discounts.</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6 col-lg-12 wow fadeInUp" data-wow-delay="0.2s">
                    <form class="dzSubscribe" action="script/mailchamp.php" method="post">
                        <div class="dzSubscribeMsg"></div>
                        <div class="form-group">
                            <div class="input-group mb-0">
                                <input name="dzEmail" required="required" type="email" class="form-control" placeholder="Your Email Address">
                                <div class="input-group-addon">
                                    <button name="submit" value="Submit" type="submit" class="btn">
                                        <svg width="21" height="21" viewBox="0 0 21 21" fill="none">
                                            <path d="M4.20972 10.7344H15.8717" stroke="#0D775E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M10.0408 4.90112L15.8718 10.7345L10.0408 16.5678" stroke="#0D775E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>