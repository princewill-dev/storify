<footer>
    <div class="footer__area footer-bg-2">
    <div class="footer__top pt-90 pb-50">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <div class="footer__widget mb-40 wow fadeInUp" data-wow-delay=".3s">
                        <div class="footer__widget-head mb-35">
                            <a href="{{ $store ? store_url($store->slug) : route('home.index') }}">
                                @if($store && $store->logo_path)
                                    <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}" style="max-height: 50px;">
                                @else
                                    <img src="{{ asset('storefront/assets/img/logo/logo-white.png') }}" alt="{{ $store->name ?? 'Store' }}">
                                @endif
                            </a>
                        </div>
                        <div class="footer__widget-content">
                            <div class="footer__social mb-30">
                                <h4>Follow our Socials</h4>
                                <ul>
                                    @if($store->facebook_url)
                                        <li><a href="{{ $store->facebook_url }}" target="_blank" class="fb"><i class="fab fa-facebook-f"></i></a></li>
                                    @endif
                                    @if($store->twitter_url)
                                        <li><a href="{{ $store->twitter_url }}" target="_blank" class="tw"><i class="fab fa-twitter"></i></a></li>
                                    @endif
                                    @if($store->instagram_url)
                                        <li><a href="{{ $store->instagram_url }}" target="_blank" class="pin"><i class="fab fa-instagram"></i></a></li>
                                    @endif
                                    @if($store->tiktok_url)
                                        <li><a href="{{ $store->tiktok_url }}" target="_blank" class="tiktok"><i class="fab fa-tiktok"></i></a></li>
                                    @endif
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="footer__widget mb-40 wow fadeInUp" data-wow-delay=".5s">
                        <div class="footer__widget-head">
                            <h4 class="footer__widget-title footer__widget-title-2">Categories</h4>
                        </div>
                        <div class="footer__widget-content">
                            <div class="footer__link footer__link-2">
                                <ul>
                                    @forelse($store->categories()->limit(5)->get() as $category)
                                        <li><a href="{{ route('home.store.category', ['store_subdomain' => $store->slug, 'category' => $category->slug]) }}">{{ $category->name }}</a></li>
                                    @empty
                                        <li><a href="{{ store_url($store->slug) }}">All Products</a></li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="footer__widget mb-40 wow fadeInUp footer__widget-pl-70"  data-wow-delay=".7s">
                        <div class="footer__widget-head">
                            <h4 class="footer__widget-title footer__widget-title-2">Links</h4>
                        </div>
                        <div class="footer__widget-content">
                            <div class="footer__link footer__link-2">
                                <ul>
                                <li><a href="{{ route('home.support.index', ['store_subdomain' => $store->slug]) }}">Contact us </a></li>
                                <li><a href="{{ route('home.store.products', ['store_subdomain' => $store->slug]) }}">Products</a></li>
                                <li><a href="{{ route('home.store.services', ['store_subdomain' => $store->slug]) }}">Services</a></li>
                                <li><a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug]) }}">Track Order</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- <div class="col-xxl-4 col-xl-3 col-lg-3 col-md-5 col-sm-6">
                    <div class="footer__widget mb-40 wow fadeInUp footer__widget-sub-pl-70"  data-wow-delay=".7s">
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
                </div> -->
            </div>
        </div>
    </div>
    <div class="footer__bottom">
        <div class="container">
            <div class="footer__bottom-inner footer__bottom-inner-2">
                <div class="row">
                    <div class="col-md-12">
                        <div class="footer__copyright footer__copyright-2 wow fadeInUp" data-wow-delay=".5s">
                            <p>Copyright © {{ date('Y') }} All Rights Reserved, Powered by <a href="{{ route('home.index') }}">{{ $company->name }}</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</footer>