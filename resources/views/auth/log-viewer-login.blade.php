<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The only page on this host worth indexing is nothing; keep it out of
         search results entirely. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ config('app.name') }}</title>

    {{--
        Styling is inline rather than through Vite on purpose. This page is the
        gate in front of the logs, and it is the one page that must still render
        when a build has not been run or the asset manifest is stale. It has no
        dependency to break.
    --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #0a0a0c;
            color: #e7e7ea;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }

        .card {
            width: 100%;
            max-width: 24rem;
            background: #14141a;
            border: 1px solid #26262f;
            border-radius: 0.75rem;
            padding: 2rem;
        }

        h1 { margin: 0 0 0.25rem; font-size: 1.125rem; font-weight: 600; letter-spacing: -0.01em; }
        .sub { margin: 0 0 1.5rem; color: #8b8b99; font-size: 0.8125rem; }

        label { display: block; font-size: 0.8125rem; font-weight: 500; margin-bottom: 0.375rem; }

        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 0.625rem 0.75rem;
            background: #0a0a0c;
            border: 1px solid #2e2e38;
            border-radius: 0.5rem;
            color: inherit;
            font: inherit;
        }

        input:focus { outline: 2px solid #a3e635; outline-offset: -1px; border-color: #a3e635; }

        .field { margin-bottom: 1rem; }

        .error { margin: 0.375rem 0 0; color: #f87171; font-size: 0.8125rem; }

        button {
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.625rem 0.75rem;
            background: #a3e635;
            border: 0;
            border-radius: 0.5rem;
            color: #0a0a0c;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { background: #b4ee4d; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Log viewer</h1>
        <p class="sub">Sign in with an administrator account to continue.</p>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    autofocus
                    required
                >
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
