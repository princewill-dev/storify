@extends('home.layout')
@section('title', 'Support - ' . $store->name)

@section('content')

<br>
<br>
<br>
<br>

<div class="page-content" style="background: #24262B;">
    <!--banner-->
    <div class="contact-bnr bg-secondary">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <div class="contact-info style-1 text-start text-white">
                        <h2 class="title wow fadeInUp" data-wow-delay="0.1s">CONTACT {{ strtoupper($store->name) }}</h2>
                        <p class="text wow fadeInUp" data-wow-delay="0.2s">
                            {{ $store->name }} is here to help you. Our experts are available to answer any questions you might have.
                        </p>
                        <div class="contact-bottom wow fadeInUp" data-wow-delay="0.3s">
                            @if($store->support_phone)
                            <div class="contact-left">
                                <h3>Call Us</h3>
                                <ul>
                                    <li>{{ $store->support_phone }}</li>
                                </ul>
                            </div>
                            @endif
                            @if($store->support_email)
                            <div class="contact-right">
                                <h3>Email Us</h3>
                                <ul>
                                    <li>{{ $store->support_email }}</li>
                                </ul>
                            </div>
                            @endif		
                        </div>
                        @if($store->address)
                        <div class="mt-4">
                            <h3 style="color: #fff;">Visit Us</h3>
                            <p class="mb-0">{{ $store->address }}</p>
                        </div>
                        @endif
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="contact-area1 style-1 m-r20 m-md-r0 wow fadeInUp" data-wow-delay="0.5s">
                        @if(session('success'))
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fa fa-check-circle me-2"></i>{{ session('success') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        @if(session('error'))
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fa fa-exclamation-circle me-2"></i>{{ session('error') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('home.support.store', ['store_slug' => $store->slug]) }}">
                            @csrf
                            <label class="form-label">Your Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input required type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <label class="form-label mt-3">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input required type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <label class="form-label mt-3">Phone Number</label>
                            <div class="input-group">
                                <input type="text" class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <label class="form-label mt-3">Message <span class="text-danger">*</span></label>
                            <div class="input-group m-b30">
                                <textarea name="message" rows="4" required class="form-control m-b10 @error('message') is-invalid @enderror">{{ old('message') }}</textarea>
                                @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <button name="submit" type="submit" class="btn w-100 btn-secondary btnhover">SEND MESSAGE</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- <div class="content-inner-2 pt-0">
        <div class="map-iframe map">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d227748.3825624477!2d75.65046970649679!3d26.88544791796718!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x396c4adf4c57e281%3A0xce1c63a0cf22e09!2sJaipur%2C+Rajasthan!5e0!3m2!1sen!2sin!4v1500819483219" style="border:0; width:100%; min-height:100%; margin-bottom: -8px;" allowfullscreen></iframe>
        </div>
    </div> -->

    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>
    <br>

</div>

@endsection