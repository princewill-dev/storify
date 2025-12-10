@extends('admin.auth.layout')

@section('title', 'Reset Password')

@section('content')
<div class="auth-wrapper">
    <div class="row">
        <div style="margin-top: 100px;" class="col-xl-6 col-lg-6 mx-auto align-self-center">
            <div class="auth-form">
                <div class="text-center mb-4">
                    @if($company->logo)
                    <img src="{{ $company->logo }}" alt="Logo" style="height:40px" class="mb-2">
                    @endif
                    <h4 class="mb-0">Enter verification code</h4>
                    <p class="text-muted small mt-1">We sent a code to your email. Enter it below and choose a new password.</p>
                </div>
            </div>

            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <form action="{{ route('admin.password.reset.process') }}" method="POST">
                @csrf
                <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control form-control-lg" value="{{ old('email', $email ?? '') }}" readonly>
                </div>
                <div class="mb-3">
                <label class="form-label">Verification code <span class="text-danger">*</span></label>
                <input type="text" name="otp" maxlength="10" class="form-control form-control-lg @error('otp') is-invalid @enderror" placeholder="e.g. 123456" value="{{ old('otp') }}" required>
                @error('otp')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                </div>
                <div class="mb-3">
                <label class="form-label">New password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control form-control-lg @error('password') is-invalid @enderror" placeholder="Min. 8 characters" required>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                </div>
                <div class="mb-3">
                <label class="form-label">Confirm password <span class="text-danger">*</span></label>
                <input type="password" name="password_confirmation" class="form-control form-control-lg" placeholder="Re-enter password" required>
                </div>

                <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">Reset password</button>
                <a href="{{ route('admin.password.forgot') }}" class="btn btn-light">Back</a>
                </div>
            </form>

            <div class="text-center mt-4">
                <a href="{{ route('admin.login') }}" class="text-decoration-none">Back to login</a>
            </div>
        </div>
    </div>
</div>
@endsection
