@extends('vendors.auth.layout')

@section('subtitle', 'Vendor Portal')

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
            <div class="input-group">
                <input
                    type="password"
                    name="password"
                    id="password"
                    class="form-control form-control-lg @error('password') is-invalid @enderror"
                    placeholder="Enter your password"
                    required
                >
                <button class="btn btn-outline-secondary toggle-password d-flex align-items-center justify-content-center" type="button" data-target="password" style="border-radius: 0 12px 12px 0; border-color: #e2e8f0; border-left: 0; background-color: transparent; z-index: 5; padding-left: 15px; padding-right: 15px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                    </svg>
                </button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-end align-items-center">
            <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.forgot-password') }}">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
    </form>

    <div class="text-center mt-4">
        <span class="text-muted">New to {{ config('app.name') }}?</span>
        <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.register') }}">Create a vendor account</a>
    </div>
    
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const eyeSvg = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
        const eyeSlashSvg = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l-.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';

        const togglePasswordButtons = document.querySelectorAll('.toggle-password');
        togglePasswordButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const targetInput = document.getElementById(targetId);
                const svg = this.querySelector('svg');
                
                if (targetInput.type === 'password') {
                    targetInput.type = 'text';
                    svg.innerHTML = eyeSlashSvg;
                } else {
                    targetInput.type = 'password';
                    svg.innerHTML = eyeSvg;
                }
            });
        });
    });
</script>
@endpush
