@extends('vendors.auth.layout')

@section('subtitle', 'Set a new password')

@section('content')
    <form method="POST" action="{{ route('vendor.auth.reset-password.update') }}" class="vstack gap-3">
        @csrf

        <input type="hidden" name="email" value="{{ old('email', $email ?? session('vendor_password_reset_email')) }}">

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
            >
            @error('otp')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label class="form-label fw-semibold">New password</label>
            <input
                type="password"
                name="password"
                class="form-control form-control-lg @error('password') is-invalid @enderror"
                placeholder="Minimum 8 characters"
                required
            >
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label class="form-label fw-semibold">Confirm password</label>
            <input
                type="password"
                name="password_confirmation"
                class="form-control form-control-lg"
                placeholder="Re-enter password"
                required
            >
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Update password</button>
    </form>

    <div class="text-center mt-4">
        <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.forgot-password') }}">Back</a>
    </div>
@endsection
