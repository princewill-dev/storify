@extends('admin.auth.layout')
@section('title', 'Superadmin')
@section('subtitle', 'Verify OTP')

@section('content')

<div class="auth-wrapper">
    <div class="row">
        <div style="margin-top: 100px;" class="col-xl-6 col-lg-6 mx-auto align-self-center">
            <div class="auth-form">
                <div class="text-center mb-4">
                    <h3 class="mb-0">Verify Your Identity</h3>
                    <p class="mb-0">Enter the 6-digit code sent to your email</p>
                </div>

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

                <form action="{{ route('admin.verify-otp.process') }}" method="POST">
                    @csrf

                    <input type="hidden" name="email" value="{{ session('email') ?? old('email') }}">

                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control form-control-lg" 
                               value="{{ session('email') ?? old('email') }}" disabled>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Verification Code <span class="text-danger">*</span></label>
                        <input type="text" name="otp" maxlength="6" 
                               class="form-control form-control-lg text-center @error('otp') is-invalid @enderror" 
                               placeholder="000000" 
                               style="letter-spacing: 10px; font-size: 24px; font-weight: 600;"
                               autocomplete="off"
                               autofocus
                               required>
                        @error('otp')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <small class="text-muted">The code will expire in 10 minutes</small>
                    </div>

                    <div class="text-center mb-3">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="fa fa-shield-alt me-2"></i>Verify & Sign In
                        </button>
                    </div>
                </form>

                <div class="text-center">
                    <p class="mb-2">Didn't receive the code?</p>
                    <form action="{{ route('admin.verify-otp.resend') }}" method="POST" class="d-inline">
                        @csrf
                        <input type="hidden" name="email" value="{{ session('email') ?? old('email') }}">
                        <button type="submit" class="btn btn-link text-primary p-0 align-baseline">Resend code</button>
                    </form>
                    <span class="text-muted mx-1">|</span>
                    <a href="{{ route('admin.login') }}" class="btn-link text-secondary">Try again</a>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

