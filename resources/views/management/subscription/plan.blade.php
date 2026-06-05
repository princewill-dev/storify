@extends('management.layout')
@section('subtitle', 'Subscription')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Subscription" subtitle="Choose a plan to activate your stores" />

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    @forelse($plans ?? [] as $plan)
    <div class="bg-white rounded-2xl shadow-sm border {{ $plan->is_default ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200' }} p-6 relative">
        @if($plan->is_default)<span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-semibold px-3 py-1 rounded-full">Recommended</span>@endif
        <h3 class="text-lg font-bold text-slate-900">{{ $plan->name }}</h3>
        <div class="mt-3 mb-4">
            <span class="text-3xl font-bold text-slate-900">₦{{ number_format($plan->amount, 2) }}</span>
            <span class="text-sm text-slate-400">/{{ $plan->interval }}</span>
        </div>
        @if($plan->features)
        <ul class="space-y-2 mb-6">
            @foreach($plan->features as $feature)
            <li class="flex items-start gap-2 text-sm text-slate-600"><i class="fi fi-rr-check-circle text-emerald-500 mt-0.5 shrink-0"></i> {{ $feature }}</li>
            @endforeach
        </ul>
        @endif
        @if($plan->is_trial) <p class="text-xs text-blue-600 font-medium mb-3">{{ $plan->trial_days }}-day free trial</p> @endif
        <form action="{{ route('management.subscription.initialize') }}" method="POST">
            @csrf
            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
            <button class="w-full py-2.5 text-sm font-semibold rounded-lg {{ $plan->is_default ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }} transition-colors">
                {{ $plan->is_trial ? 'Start Free Trial' : 'Subscribe' }}
            </button>
        </form>
    </div>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-bolt" title="No plans available" description="Subscription plans will appear here once configured by the platform admin." />
    </div>
    @endforelse
</div>
@endsection
