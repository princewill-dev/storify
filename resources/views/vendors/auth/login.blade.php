@extends('vendors.auth.layout')

@section('title', 'Vendor Portal')
@section('subtitle', 'Access your vendor dashboard')

@section('content')

    <header class="auth-heading">
        <h1>@yield('title', 'Vendor Portal')</h1>
        <p>@yield('subtitle')</p>
    </header>

    <form method="POST" action="{{ route('vendor.auth.login.store') }}" class="vstack gap-3">
        @csrf

        <div>
            <label for="email" class="form-label fw-semibold">Email address</label>
            <input
                type="email"
                name="email"
                id="email"
                class="form-control form-control-lg @error('email') is-invalid @enderror"
                placeholder="you@business.com"
                value="{{ old('email') }}"
                required
                autofocus
            >
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="password" class="form-label fw-semibold">Password</label>
            <input
                type="password"
                name="password"
                id="password"
                class="form-control form-control-lg @error('password') is-invalid @enderror"
                placeholder="Enter your password"
                required
            >
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember">Keep me signed in</label>
            </div>

            <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.forgot-password') }}">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
    </form>

    <div class="text-center mt-4">
        <span class="text-muted">New to {{ config('app.name') }}?</span>
        <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.register') }}">Create a vendor account</a>
    </div>
    
@endsection
