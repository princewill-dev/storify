@extends('admin.auth.layout')
@section('title', 'Superadmin')
@section('subtitle', 'Onboard')

@section('content')

<div class="auth-wrapper">
    <div class="row">
        <div style="margin-top: 100px;" class="col-xl-6 col-lg-6 mx-auto align-self-center">
            <div class="auth-form">
                <div class="text-center mb-4">
                    @if($company->logo)
                    <img src="{{ $company->logo }}" alt="Logo" style="height:40px" class="mb-2">
                    @endif
                    <h3 class="mb-0">Superadmin Onboarding</h3>
                    <p class="mb-0">Create the superadmin account to get started</p>
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

                <form action="{{ route('admin.onboard.process') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror" 
                               placeholder="e.g. John Doe" value="{{ old('name') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control form-control-lg @error('email') is-invalid @enderror" 
                               placeholder="admin@example.com" value="{{ old('email') }}" required>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" class="form-control form-control-lg @error('phone') is-invalid @enderror" 
                               placeholder="+1234567890" value="{{ old('phone') }}" required>
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="password" name="password" autocomplete="new-password" 
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
                        <small class="text-muted">Minimum 8 characters</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="password" name="password_confirmation" autocomplete="new-password" 
                                   class="form-control form-control-lg ic-password" 
                                   placeholder="Confirm your password" required>
                            <span class="show-pass position-absolute top-50 end-0 me-2 translate-middle">
                                <span class="show"><i class="fa fa-eye-slash"></i></span>
                                <span class="hide"><i class="fa fa-eye"></i></span>
                            </span>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="fa fa-user-plus me-2"></i>Create Superadmin Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
