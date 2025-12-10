<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Vendor Portal')</title>
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body {
            min-height: 100vh;
            background: #f7f7f7;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(2rem, 3vw, 3rem) 1rem;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .auth-card {
            width: min(1120px, 100%);
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 12px 32px rgba(17, 24, 39, 0.08);
            padding: clamp(1.75rem, 2vw + 1rem, 2.75rem);
        }

        .auth-logo {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, #4b5563, #111827);
            color: #ffffff;
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
        }

        .auth-heading {
            text-align: center;
            margin-bottom: 1rem;
        }

        .auth-heading h1 {
            font-size: 1.65rem;
            margin-bottom: .25rem;
        }

        .auth-heading p {
            color: #6b7280;
            margin-bottom: 0;
        }

        .form-control-lg {
            border-radius: 14px;
            padding: 0.85rem 1rem;
        }

        .btn-primary {
            border-radius: 14px;
            padding: 0.85rem 1rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            background: linear-gradient(135deg, #6b7280, #111827);
            border: none;
        }

        .btn-outline-secondary {
            border-radius: 14px;
        }

        .auth-footer {
            margin-top: 1.75rem;
            text-align: center;
            color: #94a3b8;
            font-size: .9rem;
        }
    </style>
</head>
<body>
    <main class="auth-card">
        <!-- <div class="auth-heading">
            <img src="{{ $company->logo }}" alt="{{ $company->name }}" width="200px">
            <br>
            <br>
        </div> -->

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('status'))
            <div class="alert alert-primary alert-dismissible fade show" role="alert">
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>We found a few issues:</strong>
                <ul class="mb-0 mt-2 small">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @yield('content')

        <div class="auth-footer">

            <a href="{{ route('home.index') }}">Home</a>
            <br>
            <br>
            <small>&copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.</small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>