@extends('admin.auth.layout')
@section('title', 'Accept Admin Invitation')

@section('content')
<div class="text-center mb-6">
    @if($company->logo)
    <img src="{{ $company->logo }}" alt="Logo" class="h-10 mx-auto mb-3">
    @endif
    <h1 class="text-xl font-bold text-slate-900">Accept Invitation</h1>
    <p class="text-sm text-slate-500 mt-1">Set up your admin account for {{ $user->email }}</p>
</div>

@if(session('error'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
@endif

@if($errors->any())
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errors->first() }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <form action="{{ route('admin.invitation.accept.process', $token) }}" method="POST" class="space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Full Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="Your full name"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('name') border-red-300 @enderror">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
            <input type="password" name="password" required autocomplete="new-password" placeholder="Minimum 8 characters"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('password') border-red-300 @enderror">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Password</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Re-enter password"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        </div>

        <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
            Accept &amp; Continue
        </button>
    </form>
</div>
@endsection
