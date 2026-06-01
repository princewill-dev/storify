@extends('auth.business.layout')

@section('title', 'Sign In — Storify')
@section('hero_title', 'Welcome back')
@section('hero_subtitle', 'Sign in to your Storify account to manage your stores, track orders, and run your business.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Sign in</h1>
    <p class="mt-1.5 text-sm text-slate-500">Enter your credentials to access your account.</p>
</div>

@if(session('success'))
<div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-700 mb-5">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-5">{{ session('error') }}</div>
@endif
@if(session('warning'))
<div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700 mb-5">{{ session('warning') }}</div>
@endif

<form method="POST" action="{{ route('management.auth.login.store') }}" class="space-y-5">
    @csrf
    <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('email') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="you@example.com">
        @error('email')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <div class="flex items-center justify-between mb-1.5">
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <a href="{{ route('management.auth.forgot-password') }}" class="text-xs text-slate-500 hover:text-slate-700 font-medium">Forgot password?</a>
        </div>
        <input type="password" id="password" name="password" required autocomplete="current-password"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="Enter your password">
        @error('password')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Sign in
    </button>
</form>

<p class="mt-6 text-center text-sm text-slate-500">
    Don&rsquo;t have an account? <a href="{{ route('management.auth.register') }}" class="text-slate-900 hover:text-slate-700 font-semibold underline underline-offset-2">Create one</a>
</p>
@endsection
