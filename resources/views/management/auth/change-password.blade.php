@extends('auth.business.layout')

@section('title', 'Set a New Password — Storify')
@section('hero_title', 'Set a new password')
@section('hero_subtitle', 'Your password was reset by an administrator. Choose a new password to continue.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Set a new password</h1>
    <p class="mt-1.5 text-sm text-slate-500">Signed in as <span class="font-semibold text-slate-700">{{ auth()->user()?->email }}</span></p>
</div>

@if(session('warning'))
<div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ session('warning') }}</div>
@endif

@if($errors->any())
<div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('management.password.change.update') }}" class="space-y-5">
    @csrf

    <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">New password</label>
        <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('password') border-red-300 @enderror" placeholder="Min. 8 characters">
        @error('password')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 mb-1.5">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Re-enter password">
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Update password
    </button>
</form>

<form method="POST" action="{{ route('management.auth.logout') }}" class="mt-6 text-center">
    @csrf
    <button type="submit" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Sign out</button>
</form>
@endsection
