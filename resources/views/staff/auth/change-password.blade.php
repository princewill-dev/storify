<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Change Password — Storify</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon ?? asset('favicon.png') }}">
    @vite('resources/css/app.css')
</head>
<body class="h-full bg-slate-50 font-sans antialiased">

<div class="flex min-h-full">
    {{-- Left Panel — Branding --}}
    <div class="relative hidden w-3/5 flex-col bg-slate-900 lg:flex">
        <div class="absolute inset-0 overflow-hidden opacity-[0.05]">
            <svg class="absolute -top-1/2 -left-1/4 w-[150%] h-[150%]" viewBox="0 0 800 800" fill="none">
                <circle cx="400" cy="400" r="300" stroke="white" stroke-width="0.5" fill="none"/>
                <circle cx="400" cy="400" r="200" stroke="white" stroke-width="0.5" fill="none"/>
                <circle cx="400" cy="400" r="100" stroke="white" stroke-width="0.5" fill="none"/>
            </svg>
        </div>
        <div class="relative flex flex-1 flex-col justify-center p-12">
            <div class="flex items-center gap-3 mb-6">
                <img src="{{ $company->favicon ?? asset('favicon.png') }}" alt="" class="h-8 w-8 rounded-lg">
                <span class="text-lg font-semibold text-white tracking-tight">{{ $company->name ?? 'Storify' }}</span>
            </div>
            <div class="max-w-sm">
                <h2 class="text-3xl font-bold text-white tracking-tight leading-tight">One more step</h2>
                <p class="mt-4 text-base text-slate-400 leading-relaxed">
                    Your administrator has set up your account. Choose a new password to secure your access and get started.
                </p>
            </div>
            <div class="mt-8 flex items-center gap-3">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-800 text-sm font-semibold text-white">
                    {{ strtoupper(substr($user->name ?? 'S', 0, 2)) }}
                </span>
                <div>
                    <p class="text-sm font-medium text-white">{{ $user->name }}</p>
                    <p class="text-xs text-slate-500">{{ $user->email }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Right Panel — Form --}}
    <div class="flex flex-1 flex-col justify-center bg-white">
        <div class="flex items-center gap-2 px-8 pt-8 lg:hidden">
            <img src="{{ $company->favicon ?? asset('favicon.png') }}" alt="" class="h-7 w-7 rounded-lg">
            <span class="text-base font-semibold text-slate-900">{{ $company->name ?? 'Storify' }}</span>
        </div>

        <div class="w-full max-w-md mx-auto px-6 lg:px-0">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Change your password</h1>
                <p class="mt-1.5 text-sm text-slate-500">Your account requires a new password before you can continue.</p>
            </div>

            @if(session('error'))
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-5">{{ session('error') }}</div>
            @endif

            <form method="POST" action="{{ route('staff.password.change.store') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">New Password</label>
                    <input type="password" id="password" name="password" required autofocus
                           class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="Min. 8 characters">
                    @error('password')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                           class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password_confirmation') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="Re-enter password">
                </div>

                <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
                    Update Password &amp; Continue
                </button>
            </form>
        </div>
    </div>
</div>

</body>
</html>
