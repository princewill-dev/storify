@extends('storefront.layout')
@section('title', $service->name)

@section('content')
<section class="product__area pt-105 pb-110 grey-bg-2">
   <div class="container">
      <div class="row">
         <div class="col-xxl-12">
            <div class="product__details-wrapper">
               <div class="row">
                  <div class="col-xxl-6 col-xl-6 col-lg-6 col-md-12">
                     <div class="product__details-thumb-wrapper d-sm-flex align-items-start mb-50">
                        <div class="product__details-thumb-tab mr-20">
                           <nav>
                              <div class="nav nav-tabs flex-nowrap flex-sm-column" id="nav-tab" role="tablist">
                                 @foreach($galleryItems as $idx => $item)
                                    <button class="nav-link {{ $idx === 0 ? 'active' : '' }}" id="nav-{{ $idx }}-tab" data-bs-toggle="tab" data-bs-target="#nav-{{ $idx }}" type="button" role="tab" aria-controls="nav-{{ $idx }}" aria-selected="{{ $idx === 0 ? 'true' : 'false' }}">
                                       <img src="{{ $item['thumb'] }}" alt="" style="width:85px;height:85px;object-fit:cover;">
                                    </button>
                                 @endforeach
                              </div>
                           </nav>
                        </div>
                        <div class="product__details-thumb-tab-content">
                           <div class="tab-content" id="nav-tabContent">
                              @forelse($galleryItems as $idx => $item)
                                 <div class="tab-pane fade {{ $idx === 0 ? 'show active' : '' }}" id="nav-{{ $idx }}" role="tabpanel" aria-labelledby="nav-{{ $idx }}-tab">
                                    <div class="product__details-thumb-big w-img">
                                       <img src="{{ $item['full'] }}" alt="">
                                    </div>
                                 </div>
                              @empty
                                 <div class="tab-pane fade show active">
                                    <div class="product__details-thumb-big w-img">
                                       <img src="{{ $placeholder }}" alt="">
                                    </div>
                                 </div>
                              @endforelse
                           </div>
                        </div>
                     </div>
                  </div>
                  <div class="col-xxl-6 col-xl-6 col-lg-6 col-md-12">
                     <div class="product__details-content">
                        <h3 class="product__details-title">{{ $service->name }}</h3>
                        <div class="product__details-price">
                           <span class="price-new">{{ $service->currency->symbol ?? '' }}{{ number_format($service->amount, 2) }}</span>
                        </div>
                        <div class="product__details-meta mb-25">
                           <ul>
                              <li>Code: <span>{{ $service->service_code }}</span></li>
                              <li>Category: <span>Service</span></li>
                           </ul>
                        </div>
                        <div class="product__details-text mb-30">
                           {!! $service->description !!}
                        </div>
                        <div class="product__details-action">
                           <a href="https://wa.me/{{ $store->whatsapp_number }}?text=I'm interested in your service: {{ $service->name }}" target="_blank" class="m-btn m-btn-border m-btn-border-5">
                              Contact via WhatsApp
                           </a>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>
@endsection
