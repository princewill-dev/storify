@extends('storefront.layout')
@section('title', 'Products - ' . $store->name)

@section('content')

<section class="product__area pt-105 pb-110 grey-bg-2">
   <div class="container">
      <div class="row">
         <div class="col-xxl-12">
            <div class="section__title-wrapper text-center mb-60">
               <h2 class="section__title">Our Products</h2>
               <p>Explore our wide range of products</p>
            </div>
         </div>
      </div>

      <div class="row" id="main-content-area">
        
          <!-- Products Loop -->
          @foreach($products as $product)
             <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-product">
                <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}" class="product__item white-bg mb-30 wow fadeInUp d-block" data-wow-delay=".3s" style="text-decoration:none;color:inherit;">
                   <div class="product__thumb">
                      <div class="product__thumb-inner fix w-img">
                         @if($product->images && $product->images->count() > 0)
                            <img src="{{ asset('storage/' . $product->images->first()->path) }}" alt="{{ $product->name }}">
                         @else
                            <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}">
                         @endif
                      </div>
                   </div>
                   <div class="product__content">
                      <h3 class="product__title product__title2">{{ $product->name }}</h3>
                      <p class="product__author">by <span>{{ $store->name }}</span> in <span>{{ $product->category->name ?? 'Products' }}</span></p>
                      <div class="product__ratings" style="color:#f59e0b;display:flex;gap:1px;font-size:12px;">
                         <i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i>
                      </div>
                      <div class="product__meta d-flex justify-content-between align-items-end mt-15">
                         <div class="product__price">
                            <span>{{ $product->display_price ?? $product->price_currency_symbol . number_format($product->amount ?? 0, 2) }}</span>
                            @if(isset($product->display_price_was) && $product->display_price_was)
                               <p><del>{{ $product->display_price_was }}</del></p>
                            @else
                               <p style="visibility: hidden;">Sale</p>
                            @endif
                         </div>                        
                      </div>
                      <div class="pricing__buy mt-3">
                         <button class="m-btn m-btn-border w-100 add-to-cart-btn-index" data-product-id="{{ $product->id }}" style="justify-content:center;"
                            onclick="event.preventDefault();event.stopPropagation();">
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
                  <h4>No products found</h4>
                  <p class="text-muted">Please check back later</p>
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
