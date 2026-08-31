<div class="plan-card relative bg-white rounded-2xl shadow-sm border {{ $plan->is_default ? 'border-slate-900 shadow-md' : 'border-slate-200' }} flex flex-col h-full hover:shadow-lg transition-shadow duration-200">
    @if($plan->is_default)
    <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-xs font-semibold px-4 py-1 rounded-full tracking-wide">Recommended</span>
    @endif

    <h3 class="plan-name">{{ $plan->name }}</h3>
    <p class="plan-desc">{{ $plan->description ?? 'All the essentials to get started.' }}</p>

    <div style="margin-bottom: 32px;">
        <span class="plan-price">₦{{ number_format($plan->amount, 2) }}</span>
        <span class="plan-interval">/{{ $plan->interval }}</span>
        @if($plan->interval === 'yearly')
        <p class="plan-savings">Billed annually</p>
        @endif
    </div>

    @if($plan->features)
    <ul class="plan-features">
        @foreach($plan->features as $feature)
        <li><i class="fi fi-rr-check-circle"></i> {{ $feature }}</li>
        @endforeach
    </ul>
    @endif

    @if(($trial['enabled'] ?? true))
        <p class="plan-trial-notice">{{ $trial['days'] ?? 7 }}-day free trial · Cancel anytime</p>
    @endif

    <form action="{{ route('management.subscription.select-plan') }}" method="POST">
        @csrf
        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
        <button type="submit" class="plan-btn {{ $plan->is_default ? 'plan-btn-primary' : 'plan-btn-secondary' }}">
            Get Started
        </button>
    </form>
</div>
