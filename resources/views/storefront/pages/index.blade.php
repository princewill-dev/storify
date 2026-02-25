@extends('storefront.layout')
@section('title', $store->name)

@section('content')

<section class="product__area pt-105 pb-110 grey-bg-2">
   <div class="container">
      <div class="row">
         <div class="col-xxl-12">
            <div class="section__title-wrapper text-center mb-60">
               <h2 class="section__title">{{ $store->name }}</h2>
               <p>{{ $store->description ?? 'Welcome to our store' }}</p>
            </div>
         </div>
      </div>

      <!-- Tab Navigation -->
      @if($products->isNotEmpty() || $services->isNotEmpty())
      <div class="row mb-40">
        <div class="col-12 d-flex justify-content-center">
            <ul class="nav nav-pills" id="store-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-pill px-4" id="pills-all-tab" data-bs-toggle="pill" data-bs-target="#pills-all" type="button" role="tab" aria-controls="pills-all" aria-selected="true" onclick="filterView('all')">All</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill px-4" id="pills-products-tab" data-bs-toggle="pill" data-bs-target="#pills-products" type="button" role="tab" aria-controls="pills-products" aria-selected="false" onclick="filterView('products')">Products</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill px-4" id="pills-services-tab" data-bs-toggle="pill" data-bs-target="#pills-services" type="button" role="tab" aria-controls="pills-services" aria-selected="false" onclick="filterView('services')">Services</button>
                </li>
            </ul>
        </div>
      </div>
      @endif

      <div class="row" id="main-content-area">
        
         <!-- Products Loop -->
         @foreach($products as $product)
            <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-product">
               <div class="product__item white-bg mb-30 wow fadeInUp" data-wow-delay=".3s">
                  <div class="product__thumb {{ $product->images && $product->images->count() > 0 && in_array(strtolower(pathinfo($product->images->first()->path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'avi', 'mpeg']) ? 'has-video' : '' }}">
                     <div class="product__thumb-inner fix w-img position-relative">
                        @if($product->images && $product->images->count() > 0)
                           @php
                              $primaryMedia = $product->images->first();
                              $mediaPath = asset('storage/' . $primaryMedia->path);
                              $extension = strtolower(pathinfo($primaryMedia->path, PATHINFO_EXTENSION));
                              $isVideo = in_array($extension, ['mp4', 'webm', 'mov', 'avi', 'mpeg']);
                           @endphp
                           
                           @if($isVideo)
                              <video class="product-video" style="width: 100%; height: 250px; object-fit: cover; cursor: pointer;" muted loop playsinline data-product-id="{{ $product->id }}">
                                 <source src="{{ $mediaPath }}" type="video/{{ $extension === 'mov' ? 'quicktime' : $extension }}">
                                 Your browser does not support the video tag.
                              </video>
                              <div class="video-play-overlay position-absolute top-50 start-50 translate-middle" style="cursor: pointer; z-index: 10;" data-video-id="{{ $product->id }}">
                                 <i class="fas fa-play-circle text-white" style="font-size: 3.5rem; opacity: 0.9; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));"></i>
                              </div>
                           @else
                              <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}">
                                 <img src="{{ $mediaPath }}" alt="{{ $product->name }}">
                              </a>
                           @endif
                        @else
                           <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}">
                              <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}">
                           </a>
                        @endif
                     </div>
                  </div>
                  <div class="product__content">
                     <h3 class="product__title product__title2">
                        <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}">{{ $product->name }}</a>
                     </h3>
                     <p class="product__author">by <a href="#">{{ $store->name }}</a> in <a href="#">{{ $product->category->name ?? 'Products' }}</a></p>
                     <div class="product__ratings">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                     </div>
                     <div class="product__meta mt-15">
                        <div class="product__price">
                           <span>{{ $product->display_price ?? $product->price_currency_symbol . number_format($product->amount ?? 0, 2) }}</span>
                           @if(isset($product->display_price_was))
                              <p><del>{{ $product->display_price_was }}</del></p>
                           @else
                              <p style="visibility: hidden;">Sale</p>
                           @endif
                        </div>
                        @if(!$product->has_variants)
                          @php($stockQtyDisplay = (int)($product->quantity ?? 0))
                          <div style="font-size:11px; color:{{ $stockQtyDisplay <= 5 && $stockQtyDisplay > 0 ? '#ef4444' : ($stockQtyDisplay === 0 ? '#9ca3af' : '#6b7280') }}; font-weight:600; letter-spacing:.04em; margin-top:4px;">
                            Stock left: {{ $stockQtyDisplay }}
                          </div>
                        @endif
                     </div>
                     <hr style="margin:12px 0 10px; border-color:#e5e7eb;">
                     <div class="pricing__buy mb-20 d-flex justify-content-between">
                        <a style="color: #000000" href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}" class="m-btn m-btn-border m-btn-border-5 flex-grow-1 me-2">
                           <span style="font-size: 12px;">View</span> 
                        </a>
                        @php($stockQty = $product->has_variants ? null : (int)($product->quantity ?? 0))
                        @if(!$product->has_variants && $stockQty <= 0)
                          <span class="m-btn m-btn-border m-btn-border-5 flex-grow-1 ms-2" style="color:#9ca3af;border-color:#d1d5db;cursor:not-allowed;text-align:center;" title="Out of stock">
                            <span style="font-size:12px;">Out of stock</span>
                          </span>
                        @else
                          <a style="color: #000000" href="javascript:void(0);" class="m-btn m-btn-border m-btn-border-5 flex-grow-1 ms-2 add-to-cart-btn-index" data-product-id="{{ $product->id }}" data-max-stock="{{ $stockQty ?? '' }}">
                             <span style="font-size: 12px;"><i class="fas fa-shopping-cart" style="font-size: 12px;"></i></span> 
                          </a>
                        @endif
                     </div>
                  </div>
               </div>
            </div>
         @endforeach

         <!-- Services Loop -->
         @foreach($services as $service)
            <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-service">
               <div class="product__item white-bg mb-30 wow fadeInUp" data-wow-delay=".3s">
                  <div class="product__thumb">
                     <div class="product__thumb-inner fix w-img">
                        <a href="{{ store_url($store->slug, 'services/' . $service->slug . '-' . $service->service_code) }}">
                           @if($service->images && $service->images->count() > 0)
                              <img src="{{ asset('storage/' . $service->images->first()->path) }}" alt="{{ $service->name }}">
                           @else
                              <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $service->name }}">
                           @endif
                        </a>
                        <div class="product__action-2">
                            <span class="badge bg-info text-white">Service</span>
                        </div>
                     </div>
                  </div>
                  <div class="product__content">
                     <h3 class="product__title product__title2">
                        <a href="{{ store_url($store->slug, 'services/' . $service->slug . '-' . $service->service_code) }}">{{ $service->name }}</a>
                     </h3>
                     <p class="product__author">by <a href="#">{{ $store->name }}</a> in <a href="#">Services</a></p>
                    
                     <div class="product__meta d-flex justify-content-between align-items-end mt-15">
                        <div class="product__price">
                           <span>{{ $service->currency->symbol ?? '' }}{{ number_format($service->amount, 2) }}</span>
                           <p style="visibility: hidden;">Sale</p>
                        </div>
                        <div class="pricing__buy mb-20">
                           <a href="{{ store_url($store->slug, 'services/' . $service->slug . '-' . $service->service_code) }}" class="m-btn m-btn-border m-btn-border-5 w-100">
                              <span></span> View
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         @endforeach

         @if($products->isEmpty() && $services->isEmpty())
            <div class="col-12">
               <div class="text-center py-5">
                  <h4>No items available at the moment</h4>
                  <p class="text-muted">Please check back later</p>
               </div>
            </div>
         @endif
      </div>

      <div class="row">
         <div class="col-12">
             <!-- Pagination for Products -->
             @if($products->hasPages())
                <div class="d-flex justify-content-center mt-4 product-pagination">
                   {{ $products->links() }}
                </div>
             @endif
             <!-- Pagination for Services -->
             @if($services->hasPages())
                <div class="d-flex justify-content-center mt-4 service-pagination">
                   {{ $services->links() }}
                </div>
             @endif
         </div>
      </div>
   </div>
</section>

<style>
.nav-pills .nav-link.active {
    background-color: var(--tp-theme-primary);
}
.nav-pills .nav-link {
    color: #000;
}

/* Disable overlay for videos */
.product__thumb.has-video::after {
    display: none !important;
}

/* Hide play button when video is playing */
.product-video.playing + .video-play-overlay {
    display: none;
}
</style>

<script>
function filterView(type) {
    const products = document.querySelectorAll('.type-product');
    const services = document.querySelectorAll('.type-service');
    const prodPag = document.querySelector('.product-pagination');
    const servPag = document.querySelector('.service-pagination');
    
    if (type === 'all') {
        products.forEach(el => el.style.display = 'block');
        services.forEach(el => el.style.display = 'block');
        if(prodPag) prodPag.style.display = 'flex';
        if(servPag) servPag.style.display = 'flex';
    } else if (type === 'products') {
        products.forEach(el => el.style.display = 'block');
        services.forEach(el => el.style.display = 'none');
        if(prodPag) prodPag.style.display = 'flex';
        if(servPag) servPag.style.display = 'none';
    } else if (type === 'services') {
        products.forEach(el => el.style.display = 'none');
        services.forEach(el => el.style.display = 'block');
        if(prodPag) prodPag.style.display = 'none';
        if(servPag) servPag.style.display = 'flex';
    }
}

// Video play/pause functionality
document.addEventListener('DOMContentLoaded', function() {
    // Handle play button clicks
    document.querySelectorAll('.video-play-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const videoId = this.getAttribute('data-video-id');
            const video = document.querySelector(`.product-video[data-product-id="${videoId}"]`);
            
            if (video) {
                video.play();
                video.classList.add('playing');
                this.style.display = 'none';
            }
        });
    });
    
    // Handle video clicks (pause when playing)
    document.querySelectorAll('.product-video').forEach(video => {
        video.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (!this.paused) {
                this.pause();
                this.classList.remove('playing');
                const videoId = this.getAttribute('data-product-id');
                const overlay = document.querySelector(`.video-play-overlay[data-video-id="${videoId}"]`);
                if (overlay) {
                    overlay.style.display = 'block';
                }
            }
        });
        
        // Show play button when video ends
        video.addEventListener('ended', function() {
            this.classList.remove('playing');
            const videoId = this.getAttribute('data-product-id');
            const overlay = document.querySelector(`.video-play-overlay[data-video-id="${videoId}"]`);
            if (overlay) {
                overlay.style.display = 'block';
            }
        });
    });
});
</script>

@endsection
