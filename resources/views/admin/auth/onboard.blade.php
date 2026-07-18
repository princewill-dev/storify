@extends('admin.auth.layout')
@section('title', 'Platform Setup')

@section('content')
<div class="text-center mb-6">
    @if($company->logo)
    <img src="{{ $company->logo }}" alt="Logo" class="h-10 mx-auto mb-3">
    @endif
    <h1 class="text-xl font-bold text-slate-900">Platform Setup</h1>
    <p class="text-sm text-slate-500 mt-1">Create the first platform administrator account</p>
</div>

@if(session('success'))
<div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-800">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
@endif
@if($errors->any())
<div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">Please fix the errors below.</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <form action="{{ route('admin.setup.process') }}" method="POST" class="space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Full Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus placeholder="e.g. John Doe"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('name') border-red-300 @enderror">
            @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Email Address</label>
            <input type="email" name="email" value="{{ old('email') }}" required placeholder="admin@example.com"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('email') border-red-300 @enderror">
            @error('email')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone Number</label>
            <input type="tel" name="phone" value="{{ old('phone') }}" required placeholder="+1234567890"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('phone') border-red-300 @enderror">
            @error('phone')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Password</label>
            <input type="password" name="password" autocomplete="new-password" required placeholder="Minimum 8 characters"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('password') border-red-300 @enderror">
            @error('password')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirm Password</label>
            <input type="password" name="password_confirmation" autocomplete="new-password" required placeholder="Confirm your password"
                class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        </div>

        <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Create Superadmin Account</button>
    </form>

    <p class="text-center text-xs text-slate-400 mt-4">This account will have full access to manage the entire platform.</p>
</div>
</div>
@endsection
