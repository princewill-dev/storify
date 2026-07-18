@extends('admin.auth.layout')
@section('title', 'Forgot Password')

@section('content')
<div class="text-center mb-6">
    @if($company->logo)
    <img src="{{ $company->logo }}" alt="Logo" class="h-10 mx-auto mb-3">
    @endif
    <h1 class="text-xl font-bold text-slate-900">Reset your password</h1>
    <p class="text-sm text-slate-500 mt-1">Enter your email to receive a verification code.</p>
</div>

@if(session('error'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
@endif
@if(session('success'))
<div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-800">{{ session('success') }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <form action="{{ route('admin.password.forgot.process') }}" method="POST" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="hello@example.com"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        </div>
        <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Send Code</button>
    </form>
</div>

<div class="text-center mt-4">
    <a href="{{ route('admin.login') }}" class="text-xs text-slate-500 hover:text-slate-700">← Back to login</a>
</div>
@endsection
