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

      <div class="row" id="main-content-area">
        
         <!-- Products Loop -->
         @foreach($products as $product)
            <div class="col-xxl-4 col-xl-4 col-lg-4 col-md-6 item-card type-product">
               <div class="product__item white-bg mb-30 wow fadeInUp" data-wow-delay=".3s">
                  <div class="product__thumb">
                     <div class="product__thumb-inner fix w-img">
                        <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}">
                           @if($product->images && $product->images->count() > 0)
                              <img src="{{ asset('storage/' . $product->images->first()->path) }}" alt="{{ $product->name }}">
                           @else
                              <img src="{{ asset('Storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}">
                           @endif
                        </a>
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
                     <div class="product__meta d-flex justify-content-between align-items-end mt-15">
                        <div class="product__price">
                           <span>{{ $product->display_price ?? $product->price_currency_symbol . number_format($product->amount ?? 0, 2) }}</span>
                           @if(isset($product->display_price_was))
                              <p><del>{{ $product->display_price_was }}</del></p>
                           @else
                              <p style="visibility: hidden;">Sale</p>
                           @endif
                        </div>
                        <div class="pricing__buy mb-20">
                           <a href="{{ store_url($store->slug, 'products/' . $product->slug . '-' . $product->product_code) }}" class="m-btn m-btn-border m-btn-border-5 w-100">
                              <span></span> View Details
                           </a>
                        </div>
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
                              <img src="{{ asset('Storefront/assets/img/product/product-1.jpg') }}" alt="{{ $service->name }}">
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
                              <span></span> View Details
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
</script>

@endsection
