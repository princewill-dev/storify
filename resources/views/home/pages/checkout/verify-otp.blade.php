@extends('home.layout')
@section('title', 'Verify Email')

@section('content')

<br><br><br><br>

<div class="page-content">
    <div class="dz-bnr-inr" style="background-image:url({{ asset('home/images/background/bg-shape.jpg') }});">
        <div class="container">
            <div class="dz-bnr-inr-entry">
                <h1>Verify Your Email</h1>
                <nav aria-label="breadcrumb" class="breadcrumb-row">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('home.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('checkout.index', ['store_slug' => $store->slug]) }}">Checkout</a></li>
                        <li class="breadcrumb-item">Verify Email</li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <section class="content-inner-1">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <i class="fa fa-envelope-open text-primary" style="font-size: 64px;"></i>
                                <h2 class="mt-3">Check Your Email</h2>
                                <p class="text-muted">We've sent a 6-digit verification code to</p>
                                <p class="fw-bold">{{ $order->customer->email }}</p>
                            </div>

                            @if($errors->any())
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('checkout.verify-otp.process', ['store_slug' => $store->slug, 'order' => $order->order_number]) }}">
                                @csrf
                                
                                <div class="form-group mb-4">
                                    <label class="label-title">Verification Code *</label>
                                    <input 
                                        type="text" 
                                        name="otp" 
                                        class="form-control form-control-lg text-center @error('otp') is-invalid @enderror" 
                                        placeholder="Enter 6-digit code" 
                                        maxlength="6"
                                        pattern="[0-9]{6}"
                                        required
                                        autofocus
                                        style="font-size: 24px; letter-spacing: 10px;"
                                    >
                                    @error('otp')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="form-text text-muted">Enter the 6-digit code sent to your email</small>
                                </div>

                                <button type="submit" class="btn btn-secondary w-100 btn-lg">
                                    Verify & Continue
                                </button>
                            </form>

                            <div class="text-center mt-4">
                                <p class="text-muted">Didn't receive the code?</p>
                                <p class="small">
                                    Check your spam folder or wait a few minutes.<br>
                                    The code expires in 10 minutes.
                                </p>
                            </div>

                            <div class="alert alert-info mt-4">
                                <strong>Order #{{ $order->order_number }}</strong><br>
                                <small>Total: ₦{{ number_format($order->total, 2) }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
