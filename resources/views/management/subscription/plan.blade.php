@extends('management.layout')
@section('subtitle', 'Subscription')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Subscription" subtitle="Manage your plan and billing" />

{{-- Active subscription --}}
@if($subscription)
<div class="mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Current Plan</p>
                <h2 class="text-xl font-bold text-slate-900 mt-0.5">{{ $subscription->subscriptionPlan->name }}</h2>
                @if($subscription->isActive())
                <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                </span>
                @else
                <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[11px] font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> {{ ucfirst($subscription->status) }}
                </span>
                @endif
            </div>
            <div class="text-right">
                <p class="text-3xl font-bold text-slate-900">₦{{ number_format($subscription->subscriptionPlan->amount, 2) }}</p>
                <p class="text-xs text-slate-400">/{{ $subscription->subscriptionPlan->interval }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-slate-50 rounded-xl">
            <div>
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Started</p>
                <p class="text-sm font-medium text-slate-700 mt-0.5">{{ $subscription->starts_at?->format('M d, Y') ?? '—' }}</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Renews</p>
                <p class="text-sm font-medium text-slate-700 mt-0.5">{{ $subscription->expires_at?->format('M d, Y') ?? '—' }}</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Billing</p>
                <p class="text-sm font-medium text-slate-700 mt-0.5 capitalize">{{ $subscription->subscriptionPlan->interval }}ly</p>
            </div>
            <div>
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Next Amount</p>
                <p class="text-sm font-medium text-slate-700 mt-0.5">₦{{ number_format($subscription->subscriptionPlan->amount, 2) }}</p>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Trial status --}}
@if(!$subscription && $user->isOnTrial())
<div class="mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Current Status</p>
                <h2 class="text-xl font-bold text-slate-900 mt-0.5">Free Trial</h2>
                <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 text-[11px] font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Active
                </span>
            </div>
            <div class="text-right">
                <p class="text-3xl font-bold text-slate-900">₦0.00</p>
                <p class="text-xs text-slate-400">for {{ $trialDays }} days</p>
            </div>
        </div>
        <div class="p-4 bg-blue-50 rounded-xl">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-blue-800">{{ $user->daysLeftOnTrial() }} day{{ $user->daysLeftOnTrial() !== 1 ? 's' : '' }} remaining in your trial</p>
                    <p class="text-xs text-blue-600 mt-0.5">Your trial ends on {{ $user->trial_ends_at?->format('M d, Y') }}. Subscribe to keep your stores running.</p>
                </div>
                <a href="{{ route('management.subscription.payment') }}" class="shrink-0 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700">Subscribe Now</a>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Available plans to switch to (if subscribed) or choose (if on trial / no plan) --}}
<div class="mb-8">
    <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">
        @if($subscription) Available Plans @else Plans @endif
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @forelse($plans as $plan)
        @php $isCurrent = $subscription && $subscription->subscription_plan_id === $plan->id; @endphp
        <div class="bg-white rounded-xl shadow-sm border {{ $isCurrent ? 'border-slate-900 ring-2 ring-slate-900/10' : 'border-slate-200' }} p-6 flex flex-col {{ $isCurrent ? 'opacity-75' : '' }}">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h4 class="text-base font-bold text-slate-900">{{ $plan->name }}</h4>
                    @if($isCurrent)
                    <span class="inline-flex items-center gap-1 mt-0.5 px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-medium">Current Plan</span>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-xl font-bold text-slate-900">₦{{ number_format($plan->amount, 2) }}</p>
                    <p class="text-[11px] text-slate-400">/{{ $plan->interval }}</p>
                </div>
            </div>
            @if($plan->features)
            <ul class="space-y-1.5 mb-5 flex-1">
                @foreach($plan->features as $feature)
                <li class="flex items-start gap-2 text-[13px] text-slate-600">
                    <i class="fi fi-rr-check-circle text-emerald-500 mt-0.5 shrink-0 text-xs"></i> {{ $feature }}
                </li>
                @endforeach
            </ul>
            @endif
            @if(!$isCurrent && $subscription)
            <button onclick="openChangePlanModal({{ $plan->id }}, '{{ $plan->name }}', '₦{{ number_format($plan->amount, 2) }}/{{ $plan->interval }}')" class="w-full py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition-colors">
                Switch to {{ $plan->name }}
            </button>
            @elseif(!$subscription && !$user->selected_plan_id)
            <form action="{{ route('management.subscription.select-plan') }}" method="POST">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <button class="w-full py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition-colors">
                    {{ $trialEnabled ? 'Get Started' : 'Select Plan' }}
                </button>
            </form>
            @endif
        </div>
        @empty
        <div class="col-span-full">
            <x-management.empty-state icon="fi fi-rr-bolt" title="No plans available" description="Plans will appear once configured by the platform admin." />
        </div>
        @endforelse
    </div>
</div>

{{-- Payment history --}}
@if($payments->isNotEmpty())
<div>
    <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-4">Billing History</h3>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100">
                    <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Date</th>
                    <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Description</th>
                    <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Reference</th>
                    <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase">Amount</th>
                    <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($payments as $payment)
                <tr class="hover:bg-slate-50/50">
                    <td class="px-5 py-3">
                        <p class="text-xs font-medium text-slate-700">{{ $payment->created_at->format('M d, Y') }}</p>
                        <p class="text-[10px] text-slate-400">{{ $payment->created_at->format('h:i A') }}</p>
                    </td>
                    <td class="px-5 py-3">
                        <p class="text-xs text-slate-700">{{ $payment->metadata['plan_name'] ?? 'Subscription Payment' }}</p>
                        <p class="text-[10px] text-slate-400 capitalize">{{ $payment->payment_type }}</p>
                    </td>
                    <td class="px-5 py-3">
                        <span class="text-xs font-mono text-slate-500">{{ Str::limit($payment->reference, 16) }}</span>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <span class="text-xs font-semibold text-slate-800">₦{{ number_format($payment->amount, 2) }}</span>
                    </td>
                    <td class="px-5 py-3 text-center">
                        @if($payment->status === 'success')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Paid</span>
                        @elseif($payment->status === 'pending')
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">Pending</span>
                        @else
                        <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-medium text-red-700">Failed</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- No subscription, no trial, plans shown above via the grid --}}
@if(!$subscription && !$user->isOnTrial() && $plans->isEmpty() && !$user->selected_plan_id)
<div class="col-span-full">
    <x-management.empty-state icon="fi fi-rr-bolt" title="No plans available" description="Subscription plans will appear here once configured by the platform admin." />
</div>
@endif
@endsection

@push('modals')
{{-- Change Plan Confirmation Modal --}}
@if($subscription)
<div id="changePlanModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeChangePlanModal()"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-base font-semibold text-slate-800">Change Plan</h3>
                <button onclick="closeChangePlanModal()" class="p-1 text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form method="POST" action="{{ route('management.subscription.change-plan') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="plan_id" id="changePlanId">
                <div class="text-center py-3 bg-slate-50 rounded-lg">
                    <p class="text-sm text-slate-500">You are switching to</p>
                    <p id="changePlanName" class="text-lg font-bold text-slate-900 mt-0.5"></p>
                    <p id="changePlanPrice" class="text-xs text-slate-400 mt-0.5"></p>
                </div>
                <p class="text-xs text-slate-500 text-center">Your current billing period will remain unchanged. The new plan price will take effect on your next renewal date.</p>
                <div class="flex items-center gap-3">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Confirm Change</button>
                    <button type="button" onclick="closeChangePlanModal()" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endpush

@push('scripts')
<script>
function openChangePlanModal(id, name, price) {
    document.getElementById('changePlanId').value = id;
    document.getElementById('changePlanName').textContent = name;
    document.getElementById('changePlanPrice').textContent = price;
    document.getElementById('changePlanModal').classList.remove('hidden');
}
function closeChangePlanModal() {
    document.getElementById('changePlanModal').classList.add('hidden');
}
</script>
@endpush
