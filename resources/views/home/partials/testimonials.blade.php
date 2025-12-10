<section class="content-inner overlay-white-dark overflow-hidden" style="background-image: url('{{ asset('home/images/background/bg1.jpg') }}'); background-repeat: no-repeat; background-size: cover;">
    <div class="container">
        <div class="row about-style1 align-items-center">
            <div class="col-lg-6 m-b30">
                <div class="position-relative">
                    <div class="about-thumb wow fadeInUp" data-wow-delay="0.1s">
                        <img src="{{ asset('home/images/girl.png') }}" alt="">
                    </div>
                    <div class="our-customer wow fadeInUp" data-wow-delay="0.2s">
                        <h6>Our Satisfied User</h6>
                        <ul>
                            <li class="customer-image">
                                <img src="{{ asset('home/images/testimonial/pic1.png') }}" alt="">
                            </li>
                            <li class="customer-image">
                                <img src="{{ asset('home/images/testimonial/pic2.png') }}" alt="">
                            </li>
                            <li class="total-customer">
                                <span class="font-14">+12K</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 m-b30 wow fadeInUp" data-wow-delay="0.3s">
                <div class="px-lg-4">
                    <div class="section-head">
                        <h2 class="title">What our clients say <br> about us</h2>
                    </div>
                    @if(isset($testimonials) && $testimonials->count() > 0)
                    <div class="swiper swiper-five">
                        <div class="swiper-wrapper">
                            @foreach($testimonials as $testimonial)
                            <div class="swiper-slide">
                                <div class="about-content">
                                    <p class="para-text">{{ $testimonial->message }}</p>
                                    <div class="about-bx-detail">
                                        <div class="about-bx-pic radius">
                                            <img src="{{ $testimonial->photo }}" alt="{{ $testimonial->name }}" style="width: 50px; height: 50px; object-fit: cover;">
                                        </div>
                                        <div>
                                            <h6 class="name">{{ $testimonial->name }}</h6> 
                                            <span class="position">{{ $testimonial->occupation }}</span> 
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <div class="pagination-align">
                            <div class="about-button-prev btn-prev">
                                <i class="fas fa-chevron-left"></i>
                            </div>
                            <div class="about-button-next btn-next">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>
                    </div>
                    @else
                    <p class="text-muted">No testimonials available at the moment.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>