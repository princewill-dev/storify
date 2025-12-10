@extends('vendors.auth.layout')

@section('title', 'Reset your password')
@section('subtitle', 'We will email you a verification code')

@section('content')
    <form method="POST" action="{{ route('vendor.auth.forgot-password.send') }}" class="vstack gap-4">
        @csrf

        <div>
            <label for="email" class="form-label fw-semibold">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-control form-control-lg @error('email') is-invalid @enderror"
                placeholder="you@business.com"
                value="{{ old('email') }}"
                required
            >
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Email me the code</button>
    </form>

    <div class="text-center mt-4">
        <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.login') }}">Back to sign in</a>
    </div>
@endsection
