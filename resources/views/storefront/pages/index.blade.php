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
            <div class="store-tabs" style="display:inline-flex;gap:4px;background:#f1f5f9;padding:4px;border-radius:14px;">
                <button class="store-tab active" onclick="filterView('all')" style="padding:10px 24px;border:none;border-radius:11px;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.2s;background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(0,0,0,0.08);">All</button>
                <button class="store-tab" onclick="filterView('products')" style="padding:10px 24px;border:none;border-radius:11px;font-size:14px;font-weight:500;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">Products</button>
                <button class="store-tab" onclick="filterView('services')" style="padding:10px 24px;border:none;border-radius:11px;font-size:14px;font-weight:500;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">Services</button>
            </div>
        </div>
      </div>
      @endif

      <div class="row" id="main-content-area">
        
          <!-- Products Loop -->
          @foreach($products as $product)
             <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-product">
                <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}" class="product__item white-bg mb-30 wow fadeInUp d-block" data-wow-delay=".3s" style="text-decoration:none;color:inherit;">
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
                               </video>
                               <div class="video-play-overlay position-absolute top-50 start-50 translate-middle" style="cursor: pointer; z-index: 10;" data-video-id="{{ $product->id }}">
                                  <i class="fas fa-play-circle text-white" style="font-size: 3.5rem; opacity: 0.9; filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));"></i>
                               </div>
                            @else
                               <img src="{{ $mediaPath }}" alt="{{ $product->name }}">
                            @endif
                         @else
                            <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}">
                         @endif
                      </div>
                   </div>
                   <div class="product__content">
                      <h3 class="product__title product__title2">{{ $product->name }}</h3>
                      <p class="product__author">by <span>{{ $store->name }}</span></p>
                      <div class="product__ratings">
                         <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
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
                      </div>
                      <div class="pricing__buy mt-3">
                         @php($stockQty = $product->has_variants ? null : (int)($product->quantity ?? 0))
                         @if(!$product->has_variants && $stockQty <= 0)
                           <span class="m-btn m-btn-border w-100 d-block text-center" style="color:#9ca3af;border-color:#d1d5db;cursor:not-allowed;background:transparent;" onclick="event.preventDefault();event.stopPropagation();">
                             <span style="font-size:12px;">Out of stock</span>
                           </span>
                         @else
                            <button class="m-btn m-btn-border w-100 add-to-cart-btn-index" data-product-id="{{ $product->id }}" data-max-stock="{{ $stockQty ?? '' }}"
                               onclick="event.preventDefault();" style="justify-content:center;background:#111827;color:#fff;border-color:#111827;">
                              <span style="font-size:12px;"><i class="far fa-shopping-bag mr-1"></i> Add to cart</span>
                           </button>
                         @endif
                      </div>
                   </div>
                </a>
             </div>
          @endforeach

          <!-- Services Loop -->
          @foreach($services as $service)
             <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-service">
                <a href="{{ store_url($store->slug, 'services/' . $service->slug . '-' . $service->service_code) }}" class="product__item white-bg mb-30 wow fadeInUp d-block" data-wow-delay=".3s" style="text-decoration:none;color:inherit;">
                   <div class="product__thumb">
                      <div class="product__thumb-inner fix w-img">
                         @if($service->images && $service->images->count() > 0)
                            <img src="{{ asset('storage/' . $service->images->first()->path) }}" alt="{{ $service->name }}">
                         @else
                            <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $service->name }}">
                         @endif
                         <div class="product__action-2">
                             <span class="badge bg-info text-white">Service</span>
                         </div>
                      </div>
                   </div>
                   <div class="product__content">
                      <h3 class="product__title product__title2">{{ $service->name }}</h3>
                      <p class="product__author">by <span>{{ $store->name }}</span> in <span>Services</span></p>
                     
                      <div class="product__meta d-flex justify-content-between align-items-end mt-15">
                         <div class="product__price">
                            <span>{{ $service->currency->symbol ?? '' }}{{ number_format($service->amount, 2) }}</span>
                            <p style="visibility: hidden;">Sale</p>
                         </div>
                         <div class="pricing__buy mb-20">
                            <span class="m-btn m-btn-border m-btn-border-5" style="justify-content:center;"><span></span> View</span>
                         </div>
                      </div>
                   </div>
                </a>
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
             @if($products->hasPages())
             <nav class="store-pagination" style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:24px;">
                 @if($products->onFirstPage())
                     <span class="store-pagination__btn store-pagination__btn--disabled" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">‹</span>
                 @else
                     <a href="{{ $products->previousPageUrl() }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">‹</a>
                 @endif

                 @foreach($products->getUrlRange(1, $products->lastPage()) as $page => $url)
                     @if($page == $products->currentPage())
                         <span class="store-pagination__btn store-pagination__btn--active" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;">{{ $page }}</span>
                     @elseif($page <= 3 || $page > $products->lastPage() - 3 || abs($page - $products->currentPage()) <= 1)
                         <a href="{{ $url }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">{{ $page }}</a>
                     @elseif($page == 4 || $page == $products->lastPage() - 3)
                         <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#cbd5e1;font-size:13px;">…</span>
                     @endif
                 @endforeach

                 @if($products->hasMorePages())
                     <a href="{{ $products->nextPageUrl() }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">›</a>
                 @else
                     <span class="store-pagination__btn store-pagination__btn--disabled" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">›</span>
                 @endif
             </nav>
             @endif

             @if($services->hasPages())
             <nav class="store-pagination" style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:24px;">
                 @if($services->onFirstPage())
                     <span class="store-pagination__btn store-pagination__btn--disabled" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">‹</span>
                 @else
                     <a href="{{ $services->previousPageUrl() }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">‹</a>
                 @endif

                 @foreach($services->getUrlRange(1, $services->lastPage()) as $page => $url)
                     @if($page == $services->currentPage())
                         <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;">{{ $page }}</span>
                     @elseif($page <= 3 || $page > $services->lastPage() - 3 || abs($page - $services->currentPage()) <= 1)
                         <a href="{{ $url }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">{{ $page }}</a>
                     @elseif($page == 4 || $page == $services->lastPage() - 3)
                         <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#cbd5e1;font-size:13px;">…</span>
                     @endif
                 @endforeach

                 @if($services->hasMorePages())
                     <a href="{{ $services->nextPageUrl() }}" class="store-pagination__btn" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#64748b;font-size:13px;text-decoration:none;transition:all 0.15s;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#64748b'">›</a>
                 @else
                     <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e2e8f0;color:#cbd5e1;font-size:13px;cursor:default;">›</span>
                 @endif
             </nav>
             @endif
         </div>
      </div>
   </div>
</section>

<style>
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
    document.querySelectorAll('.store-tab').forEach(btn => {
        btn.classList.remove('active');
        btn.style.background = 'transparent';
        btn.style.color = '#64748b';
        btn.style.fontWeight = '500';
        btn.style.boxShadow = 'none';
    });
    const active = document.querySelector(`.store-tab[onclick="filterView('${type}')"]`);
    if (active) {
        active.classList.add('active');
        active.style.background = '#fff';
        active.style.color = '#0f172a';
        active.style.fontWeight = '600';
        active.style.boxShadow = '0 1px 3px rgba(0,0,0,0.08)';
    }

    const products = document.querySelectorAll('.type-product');
    const services = document.querySelectorAll('.type-service');
    const prodPag = document.querySelector('.store-pagination:first-of-type');
    const servPag = document.querySelector('.store-pagination:last-of-type');
    
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
