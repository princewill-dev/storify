<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Checkout — Storify</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
</head>
<body class="h-full font-sans antialiased">

<div class="min-h-full flex flex-col">
    <header class="flex items-center justify-between px-6 lg:px-8 py-4 bg-white border-b border-slate-200">
        <div class="flex items-center gap-3">
            <img src="{{ $company->favicon }}" alt="" class="h-8 w-8 rounded-lg">
            <span class="text-base font-semibold text-slate-900 tracking-tight">{{ $company->name ?? 'Storify' }}</span>
        </div>
        <a href="{{ route('management.plans.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back to plans</a>
    </header>

    <main class="flex-1 px-6 lg:px-8 py-12">
        <div class="max-w-lg mx-auto">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Complete Your Payment</h1>
                <p class="mt-2 text-sm text-slate-500">Review your plan and proceed with payment.</p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 sm:p-10 mb-6">
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

                <div class="border-t border-slate-100 pt-5 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Plan Price</span>
                        <span class="font-medium text-slate-700">₦{{ number_format($plan->amount, 2) }}</span>
                    </div>

                    @if($couponCode)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Coupon</span>
                        <span class="text-slate-600 font-mono text-xs">{{ $couponCode }}</span>
                    </div>
                    @endif

                    @if($discount > 0)
                    <div class="flex justify-between text-sm">
                        <span class="text-emerald-600">Discount</span>
                        <span class="font-medium text-emerald-600">−₦{{ number_format($discount, 2) }}</span>
                    </div>
                    @endif

                    <div class="flex justify-between text-lg font-bold border-t border-slate-100 pt-4 mt-3">
                        <span class="text-slate-900">Total</span>
                        <span class="text-slate-900">₦{{ number_format($total, 2) }}</span>
                    </div>
                </div>
            </div>

            @if($plan->is_trial && $plan->trial_days && $total == 0)
            <form method="POST" action="{{ route('management.subscription.activate-trial') }}">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <button type="submit" class="w-full py-3 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 shadow-md shadow-blue-100 transition-all">
                    Start {{ $plan->trial_days }}-Day Free Trial
                </button>
            </form>
            @else
            <form method="POST" action="{{ route('management.subscription.initialize') }}">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <input type="hidden" name="coupon_code" value="{{ $couponCode ?? '' }}">
                <button type="submit" class="w-full py-3 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 shadow-md transition-all">
                    Pay with Paystack · ₦{{ number_format($total, 2) }}
                </button>
            </form>
            @endif

            <p class="text-center text-xs text-slate-400 mt-5">Secure payment powered by Paystack. By proceeding you agree to our terms.</p>
        </div>
    </main>
</div>
</body>
</html>
