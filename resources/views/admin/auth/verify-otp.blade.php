@extends('admin.auth.layout')
@section('title', 'Verify OTP')

@section('content')
<div class="text-center mb-6">
    @if($company->logo)
    <img src="{{ $company->logo }}" alt="Logo" class="h-10 mx-auto mb-3">
    @endif
    <h1 class="text-xl font-bold text-slate-900">Verify Your Identity</h1>
    <p class="text-sm text-slate-500 mt-1">Enter the 6-digit code sent to your email</p>
</div>

@if(session('success'))
<div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-800">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <form action="{{ route('admin.verify-otp.process') }}" method="POST" class="space-y-4">
        @csrf
        <input type="hidden" name="email" value="{{ session('email') ?? old('email') }}">

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
            <input type="email" value="{{ session('email') ?? old('email') }}" disabled
                class="w-full rounded-lg border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Verification Code</label>
            <input type="text" name="otp" maxlength="6" autocomplete="off" autofocus required placeholder="000000"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 tracking-[0.5em] text-center text-lg font-bold @error('otp') border-red-300 @enderror">
            @error('otp')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            <p class="text-[11px] text-slate-400 mt-1.5 text-center">The code will expire in 10 minutes</p>
        </div>

        <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Verify & Sign In</button>
    </form>

    <div class="mt-4 pt-4 border-t border-slate-100 text-center space-y-2">
        <form action="{{ route('admin.verify-otp.resend') }}" method="POST">
            @csrf
            <input type="hidden" name="email" value="{{ session('email') ?? old('email') }}">
            <button type="submit" class="text-xs text-blue-600 hover:underline">Resend code</button>
        </form>
        <a href="{{ route('admin.login') }}" class="block text-xs text-slate-500 hover:text-slate-700">← Try again</a>
    </div>
</div>
</div>
@endsection
