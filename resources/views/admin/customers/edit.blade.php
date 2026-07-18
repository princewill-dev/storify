@extends('admin.layout')
@section('subtitle', 'Edit Customer')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.customers.show', $customer) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
        <i class="fi fi-rr-arrow-left"></i> Back to Customer
    </a>
</div>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Edit Customer</h2>
        <p class="text-sm text-slate-500 mt-0.5">Account ID: <code class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-600">{{ $customer->account_id }}</code></p>
    </div>
    <div>
        <span class="inline-flex items-center rounded-full border border-slate-300 bg-white text-slate-700 px-3 py-1 text-xs font-medium">Last login: {{ $customer->last_login?->format('M d, Y H:i') ?? 'Never' }}</span>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">Customer Details</h2>
            </div>
            <form method="POST" action="{{ route('admin.customers.update', $customer) }}">
                @csrf
                @method('PUT')
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">First Name</label>
                            <input type="text" name="first_name" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('first_name') border-red-500 @enderror" value="{{ old('first_name', $customer->first_name) }}" required>
                            @error('first_name')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Last Name</label>
                            <input type="text" name="last_name" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('last_name') border-red-500 @enderror" value="{{ old('last_name', $customer->last_name) }}" required>
                            @error('last_name')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
                            <input type="email" name="email" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('email') border-red-500 @enderror" value="{{ old('email', $customer->email) }}" required>
                            @error('email')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                            <input type="text" name="phone" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('phone') border-red-500 @enderror" value="{{ old('phone', $customer->phone) }}">
                            @error('phone')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Location</label>
                            <input type="text" name="location" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('location') border-red-500 @enderror" value="{{ old('location', $customer->location) }}" placeholder="City, Country">
                            @error('location')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('status') border-red-500 @enderror" required>
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <div class="text-xs text-slate-400">
                        Created: {{ $customer->created_at->format('M d, Y H:i') }}
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('admin.customers.show', $customer) }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</a>
                        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                            <i class="fi fi-rr-disk"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">Account Summary</h2>
            </div>
            <div class="p-6">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Total Orders</dt>
                        <dd class="font-medium text-slate-900">{{ $customer->orders()->count() }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Verified Email</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full {{ $customer->hasVerifiedEmail() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">
                                {{ $customer->hasVerifiedEmail() ? 'Yes' : 'No' }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Last Updated</dt>
                        <dd class="text-slate-700">{{ $customer->updated_at->format('M d, Y H:i') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
