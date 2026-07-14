<!DOCTYPE html>
<html lang="en" class="h-100 bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify — {{ config('app.name', 'Storify') }}</title>
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
    <a href="{{ url('/') }}" class="d-block text-muted small mb-3 text-start text-decoration-none">← Back to Store</a>
    <h4>Enter Code</h4>
    <p class="text-muted small mb-4">We sent a 6-digit code to <strong>{{ $email ?? 'your email' }}</strong></p>

    @if($errors->any())
        <div class="alert alert-danger small py-2">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="post" action="{{ route('account.reset-password.verify') }}">
        @csrf
        <div class="mb-3">
            <input name="otp" type="text" class="form-control" maxlength="6" placeholder="000000" required autofocus inputmode="numeric" pattern="[0-9]{6}">
        </div>
        <button type="submit" class="btn btn-dark w-100">Verify</button>
    </form>

    <p class="mt-3 mb-0">
        <a href="{{ route('account.forgot-password') }}" class="text-muted">← Back</a>
    </p>
</div>
</body>
</html>
