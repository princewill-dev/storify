@extends('admin.layout')

@section('subtitle', 'Coupons')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Coupons</h2>
        <p class="text-sm text-slate-500 mt-0.5">Create and manage discount coupons for subscription plans.</p>
    </div>
    <a href="{{ route('admin.coupons.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-plus text-sm"></i> Create Coupon
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100 bg-slate-50/50">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Coupon</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Plan</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Discount</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Usage</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Expires</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($coupons as $coupon)
                <tr class="hover:bg-slate-50/50">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-semibold text-slate-700">{{ $coupon->code }}</span>
                        </div>
                        @if($coupon->name)
                        <div class="text-xs text-slate-400">{{ $coupon->name }}</div>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if($coupon->subscriptionPlan)
                        <span class="text-slate-700">{{ $coupon->subscriptionPlan->name }}</span>
                        @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">All Plans</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        <span class="font-semibold text-slate-700">{{ $coupon->discount_label }}</span>
                        <span class="text-xs text-slate-400 ml-1">{{ $coupon->discount_type === 'percentage' ? 'off' : 'off' }}</span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="text-slate-700">{{ $coupon->uses_count }} / {{ $coupon->max_uses ?? '∞' }}</span>
                        @if($coupon->isExhausted())
                        <span class="inline-flex items-center ml-1.5 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-medium text-red-600">Exhausted</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-slate-500">
                        {{ $coupon->expires_at ? $coupon->expires_at->format('M d, Y') : 'Never' }}
                    </td>
                    <td class="py-3 px-4">
                        @if($coupon->is_active)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Inactive
                        </span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center justify-end gap-1.5">
                            <a href="{{ route('admin.coupons.edit', $coupon) }}" class="px-2.5 py-1 text-xs font-medium text-slate-600 bg-slate-100 rounded-md hover:bg-slate-200">Edit</a>
                            <form method="POST" action="{{ route('admin.coupons.toggle', $coupon) }}">
                                @csrf
                                <button class="px-2.5 py-1 text-xs font-medium {{ $coupon->is_active ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' }} rounded-md">
                                    {{ $coupon->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}" onsubmit="return confirm('Delete coupon {{ $coupon->code }}? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button class="px-2.5 py-1 text-xs font-medium text-red-600 bg-red-50 rounded-md hover:bg-red-100">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-12 text-center">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                            <i class="fi fi-rr-ticket text-2xl text-slate-400"></i>
                        </div>
                        <h3 class="text-sm font-semibold text-slate-700 mb-1">No coupons yet</h3>
                        <p class="text-sm text-slate-400 mb-4">Create your first coupon to offer discounts.</p>
                        <a href="{{ route('admin.coupons.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">
                            <i class="fi fi-rr-plus text-xs"></i> Create Coupon
                        </a>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if($coupons->hasPages())
    <div class="px-4 py-3 border-t border-slate-100 bg-slate-50/50">
        {{ $coupons->links() }}
    </div>
    @endif
</div>
@endsection
