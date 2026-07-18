@extends('admin.auth.layout')
@section('title', 'Reset Password')

@section('content')
<div class="text-center mb-6">
    @if($company->logo)
    <img src="{{ $company->logo }}" alt="Logo" class="h-10 mx-auto mb-3">
    @endif
    <h1 class="text-xl font-bold text-slate-900">Verification Code</h1>
    <p class="text-sm text-slate-500 mt-1">We sent a code to your email. Enter it below and choose a new password.</p>
</div>

@if(session('error'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
@endif
@if(session('success'))
<div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-800">{{ session('success') }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <form action="{{ route('admin.password.reset.process') }}" method="POST" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}" readonly
                class="w-full rounded-lg border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Verification Code</label>
            <input type="text" name="otp" maxlength="10" value="{{ old('otp') }}" required placeholder="e.g. 123456"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 tracking-[0.3em] text-center font-mono @error('otp') border-red-300 @enderror">
            @error('otp')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">New Password</label>
            <input type="password" name="password" required placeholder="Min. 8 characters"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('password') border-red-300 @enderror">
            @error('password')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Password</label>
            <input type="password" name="password_confirmation" required placeholder="Re-enter password"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        </div>
        <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Reset Password</button>
        <a href="{{ route('admin.password.forgot') }}" class="block text-center text-xs text-slate-500 hover:text-slate-700">← Back</a>
    </form>
</div>
</div>
@endsection
