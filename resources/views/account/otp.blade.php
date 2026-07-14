<!DOCTYPE html>
<html lang="en" class="h-100 bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email — {{ config('app.name', 'Storify') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px 32px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .card h4 { font-weight: 700; color: #0f172a; }
        .form-control { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; font-size: 18px; text-align: center; letter-spacing: 8px; }
        .form-control:focus { border-color: #0f172a; box-shadow: 0 0 0 3px rgba(15,23,42,.06); }
        .btn-dark { background: #0f172a; border: none; border-radius: 10px; padding: 12px; font-weight: 600; font-size: 14px; }
        a { color: #0f172a; font-weight: 500; font-size: 13px; }
    </style>
</head>
<body>
<div class="card text-center">
    @php $backUrl = request('store') ? url(request('store')) : url('/'); @endphp
    <a href="{{ $backUrl }}" class="d-block text-muted small mb-3 text-start text-decoration-none">← Back to Store</a>
    <h4>Verify Your Email</h4>
    <p class="text-muted small mb-4">We sent a 6-digit code to <strong>{{ $email }}</strong></p>

    @if(session('status'))
        <div class="alert alert-success small py-2">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger small py-2">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger small py-2">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="post" action="{{ route('account.verify', ['list' => $listId ?? null]) }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        @if($checkoutCode ?? null)<input type="hidden" name="checkout_code" value="{{ $checkoutCode }}"><input type="hidden" name="store" value="{{ $checkoutStore ?? '' }}">@endif
        <div class="mb-3">
            <input name="otp" type="text" class="form-control" maxlength="6" placeholder="000000" required autofocus autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}">
        </div>
        <button type="submit" class="btn btn-dark w-100">Verify</button>
    </form>

    <div class="mt-3">
        <form method="post" action="{{ route('account.verify.resend', ['list' => $listId ?? null]) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-link btn-sm text-muted">Resend Code</button>
        </form>
    </div>

    <p class="mt-2 mb-0">
        <a href="{{ route('account.login') }}" class="text-muted">← Back to Login</a>
    </p>
</div>
</body>
</html>
