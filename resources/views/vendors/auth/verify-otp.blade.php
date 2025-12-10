@extends('vendors.auth.layout')

@section('title', 'Verify your email')
@section('subtitle', 'Enter the 6-digit code we sent to your inbox')

@section('content')
    <form method="POST" action="{{ route('vendor.auth.verify-otp.store', ['vendor' => $vendor]) }}" class="vstack gap-2">
        @csrf

        <input type="hidden" name="email" value="{{ old('email', $email ?? session('pending_vendor_email')) }}">

        <div>
            <label class="form-label fw-semibold">Verification code</label>
            <input
                type="text"
                name="otp"
                maxlength="6"
                class="form-control form-control-lg text-center @error('otp') is-invalid @enderror"
                placeholder="000000"
                value="{{ old('otp') }}"
                required
                autofocus
            >
            <small class="text-muted">Codes expire after 10 minutes.</small>
            @error('otp')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Verify & Continue</button>
    </form>

    <div class="text-center mt-3">
        <form method="POST" action="{{ route('vendor.auth.verify-otp.resend', ['vendor' => $vendor]) }}" class="d-inline">
            @csrf
            <input type="hidden" name="email" value="{{ old('email', $email ?? session('pending_vendor_email')) }}">
            <button type="submit" class="btn btn-link link-primary p-0">Resend code</button>
        </form>
        <span class="text-muted mx-2">|</span>
        <a class="link-secondary text-decoration-none" href="{{ route('vendor.auth.login') }}">Back to sign in</a>
    </div>
@endsection

