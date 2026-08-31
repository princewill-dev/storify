@php $isCurrent = $subscription && $subscription->subscription_plan_id === $plan->id; @endphp
<div class="bg-white rounded-xl shadow-sm border {{ $isCurrent ? 'border-slate-900 ring-2 ring-slate-900/10' : 'border-slate-200' }} p-6 flex flex-col {{ $isCurrent ? 'opacity-75' : '' }}">
    <div class="flex items-start justify-between mb-3">
        <div>
            <h4 class="text-base font-bold text-slate-900">{{ $plan->name }}</h4>
            @if($isCurrent)
            <span class="inline-flex items-center gap-1 mt-0.5 px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-medium">Current Plan</span>
            @elseif($plan->is_default)
            <span class="inline-flex items-center gap-1 mt-0.5 px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 text-[10px] font-medium">Popular</span>
            @endif
        </div>
        <div class="text-right">
            <p class="text-xl font-bold text-slate-900">₦{{ number_format($plan->amount, 2) }}</p>
            <p class="text-[11px] text-slate-400">/{{ $plan->interval }}{{ $plan->interval_count > 1 ? 's' : '' }}</p>
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
    @if($plan->description)
    <p class="text-xs text-slate-400 mb-4 -mt-2">{{ $plan->description }}</p>
    @endif
    @if(!$isCurrent && $subscription)
    <button onclick="openChangePlanModal({{ $plan->id }}, '{{ addslashes($plan->name) }}', '₦{{ number_format($plan->amount, 2) }}/{{ $plan->interval }}')" class="w-full py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition-colors">
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
