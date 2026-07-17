<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Login — {{ $company->name }}</title>
    <link rel="shortcut icon" href="{{ $company->favicon }}" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f5f5f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 40px; width: 100%; max-width: 400px; margin: 20px; }
        .login-box h1 { font-size: 22px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; }
        .login-box p { font-size: 14px; color: #888; margin-bottom: 28px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px; }
        .form-group input { width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 15px; font-family: inherit; transition: border-color .15s; }
        .form-group input:focus { outline: none; border-color: #1a1a1a; }
        .btn { display: block; width: 100%; padding: 13px; font-size: 15px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; transition: background .15s; }
        .btn-dark { background: #1a1a1a; color: #fff; }
        .btn-dark:hover { background: #333; }
        .alert { padding: 12px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .link { text-align: center; margin-top: 20px; font-size: 13px; color: #888; }
        .link a { color: #555; font-weight: 500; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>POS Terminal</h1>
        <p>Sign in to start selling</p>

        @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('pos.login.store') }}">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" placeholder="Enter your email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-dark">Sign In</button>
        </form>

        <div class="link">
            <a href="{{ route('home.index') }}">← Back to Home</a>
            <span class="mx-2" style="color:#ccc">|</span>
            <a href="{{ route('management.auth.login') }}">Management Login</a>
        </div>
    </div>
</body>
</html>
