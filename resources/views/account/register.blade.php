<!DOCTYPE html>
<html lang="en" class="h-100 bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register — {{ config('app.name', 'Storify') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .register-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px 32px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .register-card h4 { font-weight: 700; color: #0f172a; }
        .form-control { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; font-size: 14px; }
        .form-control:focus { border-color: #0f172a; box-shadow: 0 0 0 3px rgba(15,23,42,.06); }
        .btn-dark { background: #0f172a; border: none; border-radius: 10px; padding: 12px; font-weight: 600; font-size: 14px; }
        .btn-dark:hover { background: #1e293b; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-top: 20px; }
        .info-box p { font-size: 13px; }
        a { color: #0f172a; font-weight: 500; }
    </style>
</head>
<body>
<div class="register-card">
    <a href="{{ route('home.index') }}" class="d-block text-muted small mb-3 text-decoration-none">← Back to Home</a>
    <div class="text-center mb-4">
        <h4>Create Account</h4>
        <p class="text-muted small mb-0">
            @if(request('checkout') == '1' || session('checkout_redirect'))
                Sign up to continue with your checkout
            @else
                Enter your details to get started
            @endif
        </p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger small py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="post" action="{{ route('account.register', ['list' => $listId ?? null]) }}">
        @csrf
        @if(request('checkout_code'))<input type="hidden" name="checkout_code" value="{{ request('checkout_code') }}"><input type="hidden" name="store" value="{{ request('store') }}">@endif
        <div class="mb-3">
            <label class="form-label small fw-medium">Full Name</label>
            <input name="name" type="text" class="form-control" value="{{ old('name') }}" placeholder="John Doe" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-medium">Email Address</label>
            <input name="email" type="email" class="form-control" value="{{ old('email') }}" placeholder="you@example.com" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-medium">Phone Number</label>
            <input name="phone" type="text" class="form-control" value="{{ old('phone') }}" placeholder="08012345678" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-medium">Password</label>
            <input name="password" type="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
        </div>
        <div class="mb-4">
            <label class="form-label small fw-medium">Confirm Password</label>
            <input name="password_confirmation" type="password" class="form-control" placeholder="Re-enter password" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-dark w-100">Create Account</button>
    </form>

    <div class="info-box text-center">
        <p class="mb-1 fw-medium">Ordered before?</p>
        <p class="text-muted mb-0" style="font-size:12px;">If your email is already in our system, use <a href="{{ route('account.forgot-password') }}">Forgot Password</a> to activate your account.</p>
    </div>

    <p class="text-center small mt-3 mb-0">
        Already have an account? <a href="{{ route('account.login') }}">Sign In</a>
    </p>
</div>
</body>
</html>
