<!DOCTYPE html>
<html lang="en" class="h-100 bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — {{ config('app.name', 'Storify') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px 32px; width: 100%; max-width: 420px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .card h4 { font-weight: 700; color: #0f172a; }
        .form-control { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; font-size: 14px; }
        .form-control:focus { border-color: #0f172a; box-shadow: 0 0 0 3px rgba(15,23,42,.06); }
        .btn-dark { background: #0f172a; border: none; border-radius: 10px; padding: 12px; font-weight: 600; font-size: 14px; }
        a { color: #0f172a; font-weight: 500; }
    </style>
</head>
<body>
<div class="card text-center">
    <a href="{{ route('home.index') }}" class="d-block text-muted small mb-3 text-decoration-none">← Back to Home</a>
    <h4>Set New Password</h4>
    <p class="text-muted small mb-4">For <strong>{{ $email }}</strong></p>

    @if($errors->any())
        <div class="alert alert-danger small py-2 text-start">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="post" action="{{ route('account.reset-password.form', ['token' => $token]) }}">
        @csrf
        <div class="mb-3 text-start">
            <label class="form-label small fw-medium">New Password</label>
            <input name="password" type="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
        </div>
        <div class="mb-4 text-start">
            <label class="form-label small fw-medium">Confirm Password</label>
            <input name="password_confirmation" type="password" class="form-control" placeholder="Re-enter password" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-dark w-100">Reset Password</button>
    </form>
</div>
</body>
</html>
