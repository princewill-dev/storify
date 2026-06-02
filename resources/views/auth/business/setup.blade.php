@extends('auth.business.layout')

@section('title', 'Set Up Your Business — Storify')
@section('hero_title', 'Set up your business')
@section('hero_subtitle', 'Tell us about your business so we can tailor your experience. Choose your location, model, and currency.')

@section('form')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Set Up Your Business</h1>
    <p class="mt-1.5 text-sm text-slate-500">Fill in the details below to complete your business profile.</p>
</div>

<form method="POST" action="{{ route('management.setup.store') }}" class="space-y-5">
    @csrf

    <div>
        <label for="name" class="block text-sm font-medium text-slate-700 mb-1.5">Business Name <span class="text-red-500">*</span></label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('name') border-red-300 @enderror" placeholder="Your business name">
        @error('name')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="business_location" class="block text-sm font-medium text-slate-700 mb-1.5">Business Location</label>
        <select id="business_location" name="business_location" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
            <option value="">Select country</option>
            @foreach($countries as $country)
                <option value="{{ $country }}" @selected(old('business_location') == $country)>{{ $country }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-slate-700 mb-1.5">Business Description</label>
        <textarea id="description" name="description" rows="3"
                  class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm @error('description') border-red-300 @enderror"
                  placeholder="Briefly describe what your business does...">{{ old('description') }}</textarea>
        @error('description')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="phone" class="block text-sm font-medium text-slate-700 mb-1.5">Business Phone</label>
        <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
               class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Contact number">
    </div>

    <button type="submit" class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 transition-colors">
        Complete Setup
    </button>
</form>
@endsection

@push('scripts')
<script>

@endpush
