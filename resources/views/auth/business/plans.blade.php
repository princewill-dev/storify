<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Choose a Plan — Storify</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    <style>
        .plan-card { padding: 40px 36px; }
        .plan-card .plan-name { font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 6px; margin-top: 12px; }
        .plan-card .plan-desc { font-size: 14px; color: #94a3b8; margin-bottom: 28px; line-height: 1.6; }
        .plan-card .plan-price { font-size: 48px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em; }
        .plan-card .plan-interval { font-size: 14px; color: #94a3b8; margin-left: 6px; }
        .plan-card .plan-features { list-style: none; padding: 0; margin: 0 0 40px 0; flex: 1; }
        .plan-card .plan-features li { display: flex; align-items: flex-start; gap: 12px; font-size: 14px; color: #475569; margin-bottom: 14px; line-height: 1.5; }
        .plan-card .plan-features li i { color: #10b981; margin-top: 2px; flex-shrink: 0; }
        .plan-card .plan-btn { display: block; width: 100%; padding: 16px 24px; font-size: 15px; font-weight: 600; border-radius: 14px; text-align: center; text-decoration: none; transition: all 0.2s; }
        .plan-card .plan-btn-primary { background: #2563eb; color: #fff; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3); }
        .plan-card .plan-btn-primary:hover { background: #1d4ed8; }
        .plan-card .plan-btn-secondary { background: #f1f5f9; color: #334155; }
        .plan-card .plan-btn-secondary:hover { background: #e2e8f0; }
        .plan-card .plan-trial-badge { font-size: 14px; color: #2563eb; font-weight: 500; margin-bottom: 20px; background: #eff6ff; border-radius: 10px; padding: 10px 14px; text-align: center; }
    </style>
    @stack('styles')
</head>
<body class="h-full font-sans antialiased">

<div class="min-h-full flex flex-col">
    <header class="flex items-center justify-between px-6 lg:px-8 py-4 bg-white border-b border-slate-200">
        <div class="flex items-center gap-3">
            <img src="{{ $company->favicon }}" alt="" class="h-8 w-8 rounded-lg">
            <span class="text-base font-semibold text-slate-900 tracking-tight">{{ $company->name ?? 'Storify' }}</span>
        </div>
        <form action="{{ route('management.auth.logout') }}" method="POST">
            @csrf
            <button class="text-sm text-slate-500 hover:text-slate-700">Logout</button>
        </form>
    </header>

    <main class="flex-1 px-6 lg:px-8 py-12">
        <div class="max-w-5xl mx-auto">
            <div class="text-center mb-10">
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Choose your plan</h1>
                <p class="mt-3 text-base text-slate-500 max-w-lg mx-auto">Select a plan that fits your business. You can upgrade or change anytime.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-10">
                @forelse($plans as $plan)
                <div class="plan-card relative bg-white rounded-2xl shadow-sm border {{ $plan->is_default ? 'border-blue-300 shadow-md' : 'border-slate-200' }} flex flex-col hover:shadow-lg transition-shadow duration-200">
                    @if($plan->is_default)
                    <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs font-semibold px-4 py-1 rounded-full tracking-wide">Recommended</span>
                    @endif

                    <h3 class="plan-name">{{ $plan->name }}</h3>
                    <p class="plan-desc">{{ $plan->description ?? 'All the essentials to get started.' }}</p>

                    <div style="margin-bottom: 32px;">
                        <span class="plan-price">₦{{ number_format($plan->amount, 2) }}</span>
                        <span class="plan-interval">/{{ $plan->interval }}</span>
                    </div>

                    @if($plan->features)
                    <ul class="plan-features">
                        @foreach($plan->features as $feature)
                        <li><i class="fi fi-rr-check-circle"></i> {{ $feature }}</li>
                        @endforeach
                    </ul>
                    @endif

                    @if($plan->is_trial && $plan->trial_days)
                        <p class="plan-trial-badge">{{ $plan->trial_days }}-day free trial included</p>
                    @endif

                    <a href="{{ route('management.plans.checkout', $plan) }}" id="plan_link_{{ $plan->id }}"
                       class="plan-btn {{ $plan->is_default ? 'plan-btn-primary' : 'plan-btn-secondary' }}">
                        {{ $plan->is_trial && $plan->trial_days ? 'Start Free Trial' : 'Get Started' }}
                    </a>
                </div>
                @empty
                <div class="col-span-3 text-center py-12 text-slate-400">
                    <p class="text-lg font-medium mb-2">No plans available</p>
                    <p class="text-sm">Please contact support to set up subscription plans.</p>
                </div>
                @endforelse
            </div>

            {{-- Coupon --}}
            <div class="text-center" x-data="{ open: false, code: '', message: '', valid: false }">
                <button @click="open = !open" class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                    <span x-show="!open">Have a coupon code?</span>
                    <span x-show="open">Hide coupon field</span>
                </button>
                <div x-show="open" x-transition class="mt-4 mx-auto max-w-sm">
                    <div class="flex gap-2">
                        <input type="text" x-model="code" placeholder="Enter coupon code"
                               class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <button @click="applyCoupon()"
                                class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Apply</button>
                    </div>
                    <p x-show="message" x-text="message" class="text-sm mt-2"
                       :class="{'text-emerald-600 font-medium': valid, 'text-red-500': !valid}"></p>
                </div>
            </div>
        </div>
    </main>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@push('scripts')
<script>
function applyCoupon() {
    var el = document.querySelector('[x-data]');
    if (!el || !el._x_dataStack) return;
    var alpine = el._x_dataStack[0];
    var code = alpine.code.trim();
    if (!code) return;

    fetch('/management/plans/validate-coupon', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ code: code })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alpine.message = data.valid ? data.description : (data.message || 'Invalid code.');
        alpine.valid = data.valid;
        if (data.valid) {
            document.querySelectorAll('[id^="plan_link_"]').forEach(function(el) {
                var url = new URL(el.href);
                url.searchParams.set('coupon_code', code);
                el.href = url.toString();
            });
        }
    });
}
</script>
</body>
</html>
