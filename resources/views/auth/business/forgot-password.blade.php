@extends('auth.business.layout')

@section('title', 'Forgot Password — Storify')
@section('hero_title', 'Reset your password')
@section('hero_subtitle', 'Enter your email address and we&rsquo;ll send you a verification code to reset your password.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Forgot password</h1>
    <p class="mt-1.5 text-sm text-slate-500">No worries — we&rsquo;ll send you a reset code.</p>
</div>

@if(session('status'))
<div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-700 mb-5">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('management.auth.forgot-password.send') }}" class="space-y-5">
    @csrf
    <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('email') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="you@example.com">
        @error('email')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Send reset code
    </button>
</form>

<p class="mt-6 text-center text-sm text-slate-500">
    Remember your password? <a href="{{ route('management.auth.login') }}" class="text-slate-900 hover:text-slate-700 font-semibold underline underline-offset-2">Sign in</a>
</p>
@endsection
