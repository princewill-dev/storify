@extends('admin.layout')

@section('subtitle', $coupon->exists ? 'Edit Coupon' : 'Create Coupon')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">{{ $coupon->exists ? 'Edit Coupon' : 'Create Coupon' }}</h2>
        <p class="text-sm text-slate-500 mt-0.5">{{ $coupon->exists ? 'Update coupon details.' : 'Create a new discount coupon.' }}</p>
    </div>
    <a href="{{ route('admin.coupons.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back to Coupons</a>
</div>

<form method="POST"
      action="{{ $coupon->exists ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}"
      class="max-w-2xl space-y-6">
    @csrf
    @if($coupon->exists)
        @method('PUT')
    @endif

    @if($errors->any())
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        @foreach($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Coupon Name <span class="text-slate-400 font-normal">(internal use)</span></label>
            <input type="text" name="name" value="{{ old('name', $coupon->name) }}"
                   class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm"
                   placeholder="e.g., Black Friday 2026">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Coupon Code <span class="text-red-500">*</span></label>
            <input type="text" name="code" value="{{ old('code', $coupon->code) }}" required
                   class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono uppercase"
                   placeholder="e.g., SAVE10">
            <p class="text-xs text-slate-400 mt-1">Customers will enter this code at checkout.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Applies To</label>
            <select name="subscription_plan_id" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                <option value="">All Plans</option>
                @foreach($plans as $plan)
                <option value="{{ $plan->id }}" @selected(old('subscription_plan_id', $coupon->subscription_plan_id) == $plan->id)>
                    {{ $plan->name }} ({{ ucfirst($plan->interval) }})
                </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount Type <span class="text-red-500">*</span></label>
                <select name="discount_type" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                    <option value="percentage" @selected(old('discount_type', $coupon->discount_type) === 'percentage')>Percentage (%)</option>
                    <option value="fixed" @selected(old('discount_type', $coupon->discount_type) === 'fixed')>Fixed Amount (₦)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount Value <span class="text-red-500">*</span></label>
                <input type="number" name="discount_value" value="{{ old('discount_value', $coupon->discount_value) }}" required min="0.01" step="0.01"
                       class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm"
                       placeholder="{{ old('discount_type', $coupon->discount_type) === 'fixed' ? '0.00' : '10' }}">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Max Uses <span class="text-slate-400 font-normal">(blank = unlimited)</span></label>
                <input type="number" name="max_uses" value="{{ old('max_uses', $coupon->max_uses) }}" min="1"
                       class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm"
                       placeholder="Unlimited">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5">Expiry Date <span class="text-slate-400 font-normal">(blank = never)</span></label>
                <input type="date" name="expires_at" value="{{ old('expires_at', $coupon->expires_at?->format('Y-m-d')) }}"
                       class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
            </div>
        </div>

        <div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active ?? true))
                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                <span class="text-sm font-medium text-slate-700">Active</span>
            </label>
            <p class="text-xs text-slate-400 mt-1 ml-6">Inactive coupons cannot be applied at checkout.</p>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">
            <i class="fi fi-rr-check text-xs"></i> {{ $coupon->exists ? 'Update Coupon' : 'Create Coupon' }}
        </button>
        <a href="{{ route('admin.coupons.index') }}" class="px-5 py-2.5 border border-slate-300 text-slate-700 text-sm font-semibold rounded-lg hover:bg-slate-50">Cancel</a>
    </div>
</form>
@endsection
