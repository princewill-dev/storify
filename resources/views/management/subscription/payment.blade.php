@extends('management.layout')
@section('subtitle', 'Payment')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Complete Payment" subtitle="Activate your subscription to keep your stores running" />

<div class="max-w-lg mx-auto">
    @if($isOnTrial)
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm">
        <p class="font-semibold text-amber-800">Your trial ends in {{ $daysLeft }} day{{ $daysLeft !== 1 ? 's' : '' }}</p>
        <p class="mt-1 text-amber-600">Pay now to keep your stores active without interruption.</p>
    </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 mb-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-bold text-slate-900">{{ $plan->name }}</h3>
            <span class="text-xs text-slate-400 bg-slate-100 px-2.5 py-1 rounded-full">{{ ucfirst($plan->interval) }}</span>
        </div>

        @if($plan->features)
        <ul class="space-y-2 mb-6">
            @foreach($plan->features as $feature)
            <li class="flex items-start gap-3 text-sm text-slate-600">
                <i class="fi fi-rr-check-circle text-emerald-500 mt-0.5 shrink-0"></i> {{ $feature }}
            </li>
            @endforeach
        </ul>
        @endif

        <div class="border-t border-slate-100 pt-5">
            <div class="flex justify-between text-sm">
                <span class="text-slate-500">Plan Price</span>
                <span class="font-medium text-slate-700">₦{{ number_format($plan->amount, 2) }}</span>
            </div>
            <div class="flex justify-between text-lg font-bold border-t border-slate-100 pt-4 mt-3">
                <span class="text-slate-900">Total</span>
                <span class="text-slate-900">₦{{ number_format($plan->amount, 2) }}</span>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('management.subscription.process-payment') }}">
        @csrf
        <button type="submit" class="w-full py-3 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 shadow-md transition-all">
            Pay with Paystack · ₦{{ number_format($plan->amount, 2) }}
        </button>
    </form>

    <p class="text-center text-xs text-slate-400 mt-5">Secure payment powered by Paystack. By proceeding you agree to our terms.</p>
</div>
@endsection
