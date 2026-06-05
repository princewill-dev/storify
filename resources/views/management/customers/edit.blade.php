@extends('management.layout')
@section('subtitle', 'Edit Customer')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Edit Customer" :subtitle="$customer->first_name . ' ' . $customer->last_name . ' · ' . $customer->account_id">
    <x-slot:actions>
        <a href="{{ route('management.customers.show', $customer) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Back
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Customer Details</h3>
            </div>
            <form method="POST" action="{{ route('management.customers.update', $customer) }}" class="p-5 space-y-4">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="first_name" label="First Name" :value="old('first_name', $customer->first_name)" required :error="$errors->first('first_name')" />
                    <x-management.form-input name="last_name" label="Last Name" :value="old('last_name', $customer->last_name)" required :error="$errors->first('last_name')" />
                    <x-management.form-input name="email" label="Email Address" type="email" :value="old('email', $customer->email)" required :error="$errors->first('email')" />
                    <x-management.form-input name="phone" label="Phone" :value="old('phone', $customer->phone)" :error="$errors->first('phone')" />
                    <x-management.form-input name="location" label="Location" :value="old('location', $customer->location)" placeholder="City, Country" :error="$errors->first('location')" />
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                            @foreach($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                    <span class="text-xs text-slate-400">Created {{ $customer->created_at->format('d M Y, H:i') }}</span>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('management.customers.show', $customer) }}" class="px-4 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50">Cancel</a>
                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-slate-900 rounded-lg hover:bg-slate-800">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Account Summary</h3>
            </div>
            <div class="p-5 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Total Orders</span><span class="font-semibold text-slate-800">{{ $customer->orders()->count() }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Email Verified</span><span class="font-medium {{ $customer->hasVerifiedEmail() ? 'text-emerald-600' : 'text-slate-400' }}">{{ $customer->hasVerifiedEmail() ? 'Yes' : 'No' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Last Updated</span><span class="text-slate-600">{{ $customer->updated_at->format('d M Y, H:i') }}</span></div>
            </div>
        </div>
    </div>
</div>
@endsection
