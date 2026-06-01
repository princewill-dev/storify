@extends('auth.business.layout')

@section('title', 'Create Account — Storify')
@section('hero_title', 'Start your journey')
@section('hero_subtitle', 'Create your Storify account in seconds. Set up stores, invite your team, and start selling online.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Create your account</h1>
    <p class="mt-1.5 text-sm text-slate-500">Fill in the details below to get started.</p>
</div>

<form method="POST" action="{{ route('management.auth.register.store') }}" class="space-y-4">
    @csrf
    <div>
        <label for="full_name" class="block text-sm font-medium text-slate-700 mb-1.5">Full name</label>
        <input type="text" id="full_name" name="full_name" value="{{ old('full_name') }}" required autocomplete="name"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('full_name') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="John Doe">
        @error('full_name')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('email') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="you@example.com">
        @error('email')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="phone" class="block text-sm font-medium text-slate-700 mb-1.5">Phone number</label>
        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('phone') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="08000000000">
        @error('phone')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="Min. 8 characters">
        <p class="text-xs text-slate-400 mt-1">Must be at least 8 characters</p>
        @error('password')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password_confirmation') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" placeholder="Re-enter your password">
        @error('password_confirmation')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Create account
    </button>
</form>

<p class="mt-6 text-center text-sm text-slate-500">
    Already have an account? <a href="{{ route('management.auth.login') }}" class="text-slate-900 hover:text-slate-700 font-semibold underline underline-offset-2">Sign in</a>
</p>
@endsection
