@extends('admin.auth.layout')

@section('title', 'Forgot Password')

@section('content')
<div class="auth-wrapper">
  <div class="row">
    <div style="margin-top: 100px;" class="col-xl-6 col-lg-6 mx-auto align-self-center">
      <div class="auth-form">
        <div class="auth-form">
          <div class="text-center mb-4">
            @if($company->logo)
              <img src="{{ $company->logo }}" alt="Logo" style="height:40px" class="mb-2">
            @endif
            <h4 class="mb-0">Reset your password</h4>
            <p class="text-muted small mt-1">Enter your email to receive a verification code.</p>
          </div>

          @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
          @endif
          @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
          @endif

          <form action="{{ route('admin.password.forgot.process') }}" method="POST">
            @csrf
            <div class="mb-3">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control form-control-lg @error('email') is-invalid @enderror" placeholder="hello@example.com" value="{{ old('email') }}" required>
              @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
            </div>

            <div class="d-grid">
              <button type="submit" class="btn btn-primary btn-lg">Send code</button>
            </div>
          </form>

          <div class="text-center mt-4">
            <a href="{{ route('admin.login') }}" class="text-decoration-none">Back to login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
