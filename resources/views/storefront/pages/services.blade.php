@extends('storefront.layout')
@section('title', 'Services - ' . $store->name)

@section('content')

<section class="product__area pt-105 pb-110 grey-bg-2">
   <div class="container">
      <div class="row">
         <div class="col-xxl-12">
            @if($services->isNotEmpty())
            <div class="section__title-wrapper text-center mb-60">
               <h2 class="section__title">Our Services</h2>
               <p>Professional services we offer</p>
            </div>
            @endif
         </div>
      </div>

      <div class="row" id="main-content-area">
        
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
                              <span></span> View Details
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         @endforeach

         @if($services->isEmpty())
            <div class="col-12">
               <div class="text-center py-5">
                  <h4>No services found</h4>
                  <p class="text-muted">Please check back later</p>
               </div>
            </div>
         @endif
      </div>

      <div class="row">
         <div class="col-12">
             <!-- Pagination -->
             @if($services->hasPages())
                <div class="d-flex justify-content-center mt-4 service-pagination">
                   {{ $services->links() }}
                </div>
             @endif
         </div>
      </div>
   </div>
</section>

@endsection
