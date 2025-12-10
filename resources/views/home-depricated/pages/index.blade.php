@extends('home.layout')
@section('title', 'Home')

@section('content')

<!--Swiper Banner Start -->
<div class="main-slider style-1"> 
    <div class="main-swiper">
        <div class="swiper-wrapper">
            @if(isset($slides) && $slides->count())
                @foreach($slides as $slide)
                    <div class="swiper-slide bg-light">
                        <div class="container-fluid">
                            <div class="banner-content">
                                <div class="row gx-0">

                                    <div class="col-md-6 col-sm-6 align-self-center">
                                        <div class="swiper-content">
                                            <div class="content-info">
                                                <h1 class="title mb-2" data-swiper-parallax="-20">{{ $slidesVm[$slide->id]['title'] ?? '' }}</h1>
                                                <p class="text mb-0" data-swiper-parallax="-40">{{ $slidesVm[$slide->id]['firstSentence'] ?? '' }}</p>
                                                <div class="swiper-meta-items" data-swiper-parallax="-50">
                                                    <div class="meta-content">
                                                        <span class="price-name">Price</span>
                                                        <span class="price-num">{{ $slidesVm[$slide->id]['price'] ?? '' }}</span>
                                                    </div>
                                                    <div class="meta-content">
                                                        <span class="color-name">Color</span>
                                                        <div class="d-flex align-items-center color-filter">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="radioNoLabel" id="radioNoLabel1_{{ $loop->index }}" value="#24262B" aria-label="..." checked>
                                                                <span></span>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="radioNoLabel" id="radioNoLabel2_{{ $loop->index }}" value="#0D775E" aria-label="...">
                                                                <span></span>
                                                            </div>
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="radio" name="radioNoLabel" id="radioNoLabel3_{{ $loop->index }}" value="#C7D1CF" aria-label="...">
                                                                <span></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="content-btn" data-swiper-parallax="-60">
                                                    @if($slide->product)
                                                        <a class="btn btn-secondary me-xl-3 me-2 btnhover20" href="#" data-add-to-cart data-product-id="{{ $slide->product->id }}" data-store="{{ $slide->product->store->slug }}">ADD TO CART</a>
                                                        <a class="btn btn-outline-secondary btnhover20" href="{{ route('home.products.show',['store_slug' => $slide->product->store->slug, 'slug' => $slide->product->slug, 'code' => $slide->product->product_code]) }}">VIEW DETAILS</a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-sm-6">
                                        <div class="banner-media">
                                            <div class="img-preview" data-swiper-parallax="-100">
                                                <img src="{{ $slidesImages[$slide->id] ?? asset('home/images/banner/banner-media.png') }}" alt="banner-media">
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                @forelse($fallbackProducts as $p)
                    <div class="swiper-slide bg-light">
                        <div class="container-fluid">
                            <div class="banner-content">
                                <div class="row gx-0">
                                    
                                    <div class="col-md-6 col-sm-6">
                                        <div class="banner-media">
                                            <div class="img-preview" data-swiper-parallax="-100">
                                                <img src="{{ $fallbackImages[$p->id] ?? asset('home/images/banner/banner-media.png') }}" alt="banner-media">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-sm-6 align-self-center">
                                        <div class="swiper-content">
                                            <div class="content-info">
                                                <h1 class="title mb-2" data-swiper-parallax="-20">{{ $p->name }}</h1>
                                                <p class="text mb-0" data-swiper-parallax="-40">{{ $fallbackFirst[$p->id] ?? '' }}</p>
                                                <div class="swiper-meta-items" data-swiper-parallax="-50">
                                                    <div class="meta-content">
                                                        <span class="price-name">Price</span>
                                                        <span class="price-num">{{ $featuredPrices[$p->id] ?? '' }}</span>
                                                    </div>
                                                </div>
                                                <div class="content-btn" data-swiper-parallax="-60">
                                                    <a class="btn btn-secondary me-xl-3 me-2 btnhover20" href="#" data-add-to-cart data-product-id="{{ $p->id }}" data-store="{{ $p->store->slug ?? ($mainStore->slug ?? '') }}">ADD TO CART</a>
                                                    <a class="btn btn-outline-secondary btnhover20" href="{{ route('home.products.show', ['store_slug' => $p->store->slug ?? ($mainStore->slug ?? ''), 'slug' => $p->slug, 'code' => $p->product_code]) }}">VIEW DETAILS</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="">
                        <p>No slides found</p>
                    </div>
                @endforelse
            @endif
            <!-- <div class="swiper-pagination-wrapper">
                <div class="swiper-pagination-five"></div>
                <i class="flaticon flaticon-left-chevron-1"></i>
            </div>
            <div class="swiper-button-next">
                <i class="flaticon flaticon-right-arrow"></i>
            </div> -->
        </div>
        <div class="banner-social-media">
            <ul>
                @if(isset($mainStore) && $mainStore)
                    @if($mainStore->instagram_url)
                    <li>
                        <a target="_blank" href="{{ $mainStore->instagram_url }}">Instagram</a>
                    </li>
                    @endif
                    @if($mainStore->facebook_url)
                    <li>
                        <a target="_blank" href="{{ $mainStore->facebook_url }}">Facebook</a>
                    </li>
                    @endif
                    @if($mainStore->twitter_url)
                    <li>
                        <a target="_blank" href="{{ $mainStore->twitter_url }}">twitter</a>
                    </li>
                    @endif
                    @if($mainStore->tiktok_url)
                    <li>
                        <a target="_blank" href="{{ $mainStore->tiktok_url }}">Tiktok</a>
                    </li>
                    @endif
                @endif
            </ul>
            
        </div>
        <div class="left-text-bar justify-content-center">
            <a href="contact-us-1.html" class="service-btn btn-light">Let’s talk</a>
        </div>
    </div>
</div>		
<!--Swiper Banner End-->

<!-- Feature Product -->
<!-- <section class="content adv-area">
    <div class="container-fluid px-0">
        <div class="row product-style2 g-0">
            <div class="col-lg-6 col-md-6 p-b30 wow fadeInUp" data-wow-delay="0.1s">
                <div class="product-box style-4" style="background-image: url('{{ asset('home/images/shop/large/product1.png') }}');">
                    <div class="product-content">
                        <div class="main-content">
                            <div class="badge style-1 mb-3">From $29.05</div>
                            <h2 class="product-name">Organic Skincare for Glowing Complexion.</h2>
                            <p class="para-text">
                                Lorem Ipsum is simply dummy text of It’s easy to get lost in the world of lovely valley vapour around and the meridian sun strikes the upper surface.
                            </p>
                        </div>
                        <a href="shop-list.html" class="btn btn-outline-secondary">Shop Now</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-6 p-b30 wow fadeInUp" data-wow-delay="0.2s">
                <div class="product-box style-4" style="background-image: url('{{ asset('home/images/shop/large/product2.png') }}');">
                    <div class="product-content">
                        <div class="main-content">
                            <div class="badge style-1 mb-3">free shipping on all orders over $59</div>
                            <h2 class="product-name">Shop & shipment acrossthe whole North America.</h2>
                            <p class="para-text">
                                Lorem Ipsum is simply dummy text of It’s easy to get lost in the world of lovely valley vapour around and the meridian sun strikes the upper surface.
                            </p>
                        </div>
                        <a href="shop-list.html" class="btn btn-outline-secondary">Shop Now</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section> -->
<!-- Feature Product End -->

<!-- Company Services Feature Blocks -->
<section class="content adv-area">
    <div class="container-fluid px-0">
        <div class="row product-style2 g-0">
            @forelse($services as $i => $svc)
                <div class="col-lg-6 col-md-6 wow fadeInUp" data-wow-delay="{{ $servicesVm[$svc->id]['delay'] ?? '0.1s' }}">
                    <div class="product-box style-4" style="background-image: url('{{ $servicesVm[$svc->id]['bg'] ?? asset('home/images/shop/large/product1.png') }}'); margin: 16px;">
                        <div class="product-content">
                            <div class="main-content text-white" style="background: rgba(0,0,0,0.5); padding: 16px; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.3);">
                                <h2 class="product-name mb-2" style="color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">{{ $svc->title }}</h2>
                                <p class="mb-0" style="color: #f1f1f1; text-shadow: 0 1px 2px rgba(0,0,0,0.25);">{{ $svc->description }}</p>
                            </div>
                            <a href="{{ $svc->page_link }}" class="btn btn-secondary">Shop Now</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="text-center text-muted py-5">No services available</div>
                </div>
            @endforelse
        </div>
    </div>
    
</section>
<!-- Company Services Feature Blocks End -->

<!-- Product Start-->
<!-- <section class="content-inner overlay-white-middle">
    <div class="container">
        <div class="row product-style1">
            
            <div class="col-lg-6">
                <div class="row product-style-1">
                    <div class="col-lg-12 m-b30 wow fadeInUp" data-wow-delay="0.2s">
                        <div class="product-box style-2" style="background-image: url('{{ asset('home/images/shop/product2.png') }}');">
                            <div class="product-content">
                                <div class="main-content">
                                    <h2 class="product-name">Bamboo toothbrushes</h2>
                                    <span class="offer">Order in large quantities with negotiated rates tailored to your needs.</span>
                                </div>
                                <a href="shop-standard.html" class="btn btn-outline-secondary">Shop Now</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-12 m-b30 wow fadeInUp" data-wow-delay="0.2s">
                        <div class="product-box style-2" style="background-image: url('{{ asset('home/images/shop/product2.png') }}');">
                            <div class="product-content">
                                <div class="main-content">
                                    <h2 class="product-name">Bamboo toothbrushes</h2>
                                    <span class="offer">UP TO 60% OFF</span>
                                </div>
                                <a href="shop-standard.html" class="btn btn-outline-secondary">Shop Now</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row product-style-1">
                    <div class="col-lg-12 m-b30 wow fadeInUp" data-wow-delay="0.2s">
                        <div class="product-box style-2" style="background-image: url('{{ asset('home/images/shop/product2.png') }}');">
                            <div class="product-content">
                                <div class="main-content">
                                    <h2 class="product-name">Bamboo toothbrushes</h2>
                                    <span class="offer">UP TO 60% OFF</span>
                                </div>
                                <a href="shop-standard.html" class="btn btn-outline-secondary">Shop Now</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-12 m-b30 wow fadeInUp" data-wow-delay="0.2s">
                        <div class="product-box style-2" style="background-image: url('{{ asset('home/images/shop/product2.png') }}');">
                            <div class="product-content">
                                <div class="main-content">
                                    <h2 class="product-name">Bamboo toothbrushes</h2>
                                    <span class="offer">UP TO 60% OFF</span>
                                </div>
                                <a href="shop-standard.html" class="btn btn-outline-secondary">Shop Now</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section> -->
<!-- Product End-->

<!--Recommend Section Start-->
@include('home.partials.featured_products')
<!--Recommend Section End-->

<!-- icon-box1 -->
@include('home.components.features_cta')
<!-- icon-box1 End-->

<!-- Newsletter -->
<!-- @include('home.components.new_letter_cta') -->
<!-- Newsletter End -->


<!-- Tranding Start-->
@include('home.partials.trending_products')
<!-- Tranding Stop-->

<!-- About Start-->
@include('home.partials.testimonials')
<!-- About End -->

<!-- Blog Start -->
    @include('home.partials.blog')
<!-- Blog End -->

<!-- Feature Box -->
<div class="content-inner py-0 overlay-white-middle">
    <div class="container-fluid px-0">
        <div class="row gx-0">
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.1s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">
                        <img src="{{ asset('home/images/feature/1.png') }}" alt="">
                    </a>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.2s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">
                        <img src="{{ asset('home/images/feature/2.png') }}" alt="">
                    </a>	
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.3s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">
                        <img src="{{ asset('home/images/feature/3.png') }}" alt="">
                    </a>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.4s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">
                        <img src="{{ asset('home/images/feature/4.png') }}" alt="">
                    </a>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.5s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">
                        <img src="{{ asset('home/images/feature/5.png') }}" alt="">
                    </a>
                </div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-4 col-4 wow fadeIn" data-wow-delay="0.6s">
                <div class="insta-post dz-media dz-img-effect rotate">
                    <a href="javascript:void(0);">	
                        <img src="{{ asset('home/images/feature/6.png') }}" alt="">
                    </a>	
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Feature Box End -->

<!-- Icon Box Start -->
<section class="content-inner py-0">
    <div class="container-fluid px-0">
        <div class="row gx-0">
            <div class="col-xl-3 col-lg-3 col-sm-6">
                <div class="icon-bx-wraper style-2 bg-light wow fadeInUp" data-wow-delay="0.1s">
                    <div class="icon-bx">
                        <img src="{{ asset('home/images/svg/icon-bx/password-check.svg') }}" alt="">
                    </div>
                    <div class="icon-content">
                        <h5 class="dz-title">Filter & Discover</h5>
                        <p>Lorem Ipsum is simply dummy text of the printing and typesetting</p>
                    </div>
                    <div class="data-text">01</div>
                </div>	
            </div>
            <div class="col-xl-3 col-lg-3 col-sm-6">
                <div class="icon-bx-wraper style-2 wow fadeInUp" data-wow-delay="0.2s">
                    <div class="icon-bx">
                        <img src="{{ asset('home/images/svg/icon-bx/cart.svg') }}" alt="">
                    </div>
                    <div class="icon-content">
                        <h5 class="dz-title">Add to cart</h5>
                        <p>Lorem Ipsum is simply dummy text of the printing and typesetting</p>
                    </div>
                    <div class="data-text">02</div>
                </div>	
            </div>
            <div class="col-xl-3 col-lg-3 col-sm-6">
                <div class="icon-bx-wraper style-2 bg-light wow fadeInUp" data-wow-delay="0.3s">
                    <div class="icon-bx">
                        <img src="{{ asset('home/images/svg/icon-bx/discovery.svg') }}" alt="">
                    </div>
                    <div class="icon-content">
                        <h5 class="dz-title">Fast Shipping</h5>
                        <p>Lorem Ipsum is simply dummy text of the printing and typesetting</p>
                    </div>
                    <div class="data-text">03</div>
                </div>	
            </div>
            <div class="col-xl-3 col-lg-3 col-sm-6">
                <div class="icon-bx-wraper style-2 wow fadeInUp" data-wow-delay="0.4s">
                    <div class="icon-bx">
                        <img src="{{ asset('home/images/svg/icon-bx/box-tick.svg') }}" alt="">
                    </div>
                    <div class="icon-content">
                        <h5 class="dz-title">Enjoy The Product</h5>
                        <p>Lorem Ipsum is simply dummy text of the printing and typesetting</p>
                    </div>
                    <div class="data-text">04</div>
                </div>	
            </div>
        </div>
    </div>
</section>
<!-- Icon Box End -->

@endsection
