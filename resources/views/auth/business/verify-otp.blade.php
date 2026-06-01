@extends('auth.business.layout')

@section('title', 'Verify Email — Storify')
@section('hero_title', 'Check your email')
@section('hero_subtitle', 'We sent a 6-digit verification code to your email address. Enter it below to continue.')

@section('form')
@php $email = $email ?? session('pending_vendor_email') @endphp
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Verify your email</h1>
    <p class="mt-1.5 text-sm text-slate-500">Code sent to <span class="font-semibold text-slate-700">{{ $email }}</span></p>
</div>

<form method="POST" action="{{ route('management.auth.verify-otp.store') }}" class="space-y-5">
    @csrf
    <input type="hidden" name="email" value="{{ old('email', $email) }}">

    <div>
        <label for="otp" class="block text-sm font-medium text-slate-700 mb-1.5">Verification code</label>
        <input type="text" id="otp" name="otp" maxlength="6" value="{{ old('otp') }}" required autofocus
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm text-center tracking-[0.4em] text-lg @error('otp') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="000000">
        @error('otp')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
        <p class="text-xs text-slate-400 mt-2">Code expires in 10 minutes</p>
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Verify &amp; continue
    </button>
</form>

<div class="mt-5 flex items-center justify-center gap-4 text-sm">
    <form method="POST" action="{{ route('management.auth.verify-otp.resend') }}">
        @csrf
        <input type="hidden" name="email" value="{{ old('email', $email) }}">
        <button type="submit" class="text-slate-500 hover:text-slate-700 font-medium">Resend code</button>
    </form>
    <span class="text-slate-300">|</span>
    <a href="{{ route('management.auth.login') }}" class="text-slate-500 hover:text-slate-700 font-medium">Back to sign in</a>
</div>
@endsection
