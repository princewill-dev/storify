@extends('admin.auth.layout')
@section('title', 'Superadmin')
@section('subtitle', 'Login')

@section('content')

<div class="auth-wrapper">
    <div class="row">
        <div style="margin-top: 100px;" class="col-xl-6 col-lg-6 mx-auto align-self-center">
            <div class="auth-form" style="border: 2px solid #000; border-radius: 5px; padding: 20px;">
                <div class="text-center mb-4">
                    @if($company->logo)
                        <img src="{{ $company->logo }}" alt="Logo" style="height:40px" class="mb-2">
                    @endif
                    <br>
                    <br>
                    <h3 class="mb-0">Management Portal</h3>
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

                <form action="{{ route('admin.login.process') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control form-control-lg @error('email') is-invalid @enderror" 
                               placeholder="hello@example.com" value="{{ old('email') }}" required>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="password" name="password" autocomplete="current-password" 
                                   class="form-control form-control-lg ic-password @error('password') is-invalid @enderror" 
                                   placeholder="Enter your password" required>
                            <span class="show-pass position-absolute top-50 end-0 me-2 translate-middle">
                                <span class="show"><i class="fa fa-eye-slash"></i></span>
                                <span class="hide"><i class="fa fa-eye"></i></span>
                            </span>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap justify-content-between mb-4 mb-lg-5">
                        <a href="{{ route('admin.password.forgot') }}" class="btn-link text-primary">Forgot Password?</a>
                    </div>

                    <div class="text-center mb-4">
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                            <i class="fa fa-sign-in-alt me-2"></i>Sign In
                        </button>
                    </div>
                </form>

                <center>
                    <a href="{{ route('home.index') }}">Home</a>
                </center>
            </div>
        </div>
    </div>
</div>

@endsection
