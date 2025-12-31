@extends('storefront.layout')

@section('title', 'Support')

@section('content')

   <!-- bg shape area start -->
   <div class="bg-shape">
      <img src="{{ asset('storefront/assets/img/shape/shape-1.png') }}" alt="">
   </div>
   <!-- bg shape area end -->

   <!-- support area start -->
   <section class="support__area po-rel-z1 pt-100 pb-100">
      <div class="support__shape wow fadeInLeft" data-wow-delay=".9s">
         <img src="{{ asset('storefront/assets/img/bg/support-bg.png') }}" alt="">
      </div>
      <div class="container">
         <div class="row">
            <div class="col-xxl-6 offset-xxl-3 col-xl-6 offset-xl-3">
               <div class="page__title-wrapper text-center mb-60">
                  <h2 class="page__title-2">Welcome! <br>How can we help?</h2>
                  <p>Contact us directly or send us a message below.</p>
               </div>
            </div>
         </div>
         <div class="row">
            <div class="col-lg-6">
                <!-- Contact Info Section -->
                <div class="support__item mb-30 white-bg transition-3 wow fadeInUp" data-wow-delay=".3s">
                    <div class="support__content">
                        <h3 class="support__title mb-4">Contact Information</h3>
                        <div class="contact-info">
                            <p><strong>Store Name:</strong> {{ $store->name }}</p>
                            
                            @if($store->address)
                                <p><strong>Address:</strong><br> {{ $store->address }}</p>
                            @endif

                            @if($store->support_email)
                                <p><strong>Email:</strong> <a href="mailto:{{ $store->support_email }}">{{ $store->support_email }}</a></p>
                            @endif

                            @if($store->support_phone)
                                <p><strong>Phone:</strong> {{ $store->support_phone }}</p>
                            @endif

                             <p class="mt-4"><strong>Operating Hours:</strong><br>
                             We usually respond within 24-48 business hours.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <!-- Contact Form Section -->
                <div class="contact__form-wrapper white-bg p-4 wow fadeInUp" data-wow-delay=".5s" style="border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);">
                    <h3 class="support__title mb-4">Send us a Message</h3>

                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    <form action="{{ route('home.support.store', ['store_subdomain' => $store->slug]) }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="{{ old('name') }}" required placeholder="John Doe">
                                @error('name')
                                    <span class="text-danger small">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required placeholder="john@example.com">
                                @error('email')
                                    <span class="text-danger small">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number (Optional)</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="{{ old('phone') }}" placeholder="+1234567890">
                             @error('phone')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="message" name="message" rows="5" required placeholder="How can we help you today?">{{ old('message') }}</textarea>
                             @error('message')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>

                        <button type="submit" class="m-btn m-btn-4 w-100">Send Message</button>
                    </form>
                </div>
            </div>
         </div>
      </div>
   </section>
   <!-- support area end -->

@endsection
