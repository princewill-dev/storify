@extends('storefront.layout')
@section('title', $category->name . ' - ' . $store->name)

@section('content')

<section class="product__area pt-105 pb-110 grey-bg-2">
   <div class="container">
      <div class="row">
         <div class="col-xxl-12">
            <div class="section__title-wrapper text-center mb-60">
               <h2 class="section__title">{{ $category->name }}</h2>
               <p>Products in {{ $category->name }}</p>
            </div>
         </div>
      </div>

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
                     <p class="product__author">by <span>{{ $store->name }}</span> in <span>{{ $product->category->name ?? 'Products' }}</span></p>
                     <div class="product__ratings">
                        <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                     </div>
                     <div class="product__meta d-flex justify-content-between align-items-end mt-15">
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
                        <button class="m-btn m-btn-border w-100 add-to-cart-btn-index" data-product-id="{{ $product->id }}"
                           onclick="event.preventDefault();" style="justify-content:center;background:#111827;color:#fff;border-color:#111827;">
                           <span style="font-size:12px;"><i class="far fa-shopping-bag mr-1"></i> Add to cart</span>
                        </button>
                     </div>
                  </div>
               </a>
            </div>
         @endforeach

         @if($products->isEmpty())
            <div class="col-12">
               <div class="text-center py-5">
                  <h4>No products found in this category</h4>
                  <p class="text-muted"><a href="{{ route('home.store.products.index', ['store_subdomain' => $store->slug]) }}">Browse all products</a></p>
               </div>
            </div>
         @endif
      </div>

      <div class="row">
         <div class="col-12">
             <!-- Pagination -->
             @if($products->hasPages())
                <div class="d-flex justify-content-center mt-4 product-pagination">
                   @include("storefront.components.pagination", ["paginator" => $products])
                </div>
             @endif
         </div>
      </div>
   </div>
</section>

@endsection
