@extends('auth.business.layout')

@section('title', 'Reset Password — Storify')
@section('hero_title', 'Set a new password')
@section('hero_subtitle', 'Enter the verification code sent to your email and choose a new password.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Reset password</h1>
    <p class="mt-1.5 text-sm text-slate-500">Code sent to <span class="font-semibold text-slate-700">{{ $email ?? '' }}</span></p>
</div>

<form method="POST" action="{{ route('management.auth.reset-password.update') }}" class="space-y-5">
    @csrf
    <input type="hidden" name="email" value="{{ $email ?? '' }}">

    <div>
        <label for="otp" class="block text-sm font-medium text-slate-700 mb-1.5">Verification code</label>
        <input type="text" id="otp" name="otp" maxlength="6" required autofocus
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm text-center tracking-[0.4em] text-lg @error('otp') border-red-300 @enderror" placeholder="000000">
        @error('otp')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">New password</label>
        <input type="password" id="password" name="password" required
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password') border-red-300 @enderror" placeholder="Min. 8 characters">
        @error('password')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password_confirmation') border-red-300 @enderror" placeholder="Re-enter password">
        @error('password_confirmation')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Reset password
    </button>
</form>

<p class="mt-6 text-center text-sm text-slate-500">
    <a href="{{ route('management.auth.login') }}" class="text-slate-900 hover:text-slate-700 font-semibold underline underline-offset-2">Back to sign in</a>
</p>
@endsection
