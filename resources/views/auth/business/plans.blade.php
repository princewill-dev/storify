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
        .plan-card .plan-savings { font-size: 13px; color: #10b981; font-weight: 500; margin-top: 4px; }
        .plan-card .plan-features { list-style: none; padding: 0; margin: 0 0 40px 0; flex: 1; }
        .plan-card .plan-features li { display: flex; align-items: flex-start; gap: 12px; font-size: 14px; color: #475569; margin-bottom: 14px; line-height: 1.5; }
        .plan-card .plan-features li i { color: #10b981; margin-top: 2px; flex-shrink: 0; }
        .plan-card .plan-btn { display: block; width: 100%; padding: 16px 24px; font-size: 15px; font-weight: 600; border-radius: 14px; text-align: center; text-decoration: none; transition: all 0.2s; }
        .plan-card .plan-btn-primary { background: #2563eb; color: #fff; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3); }
        .plan-card .plan-btn-primary:hover { background: #1d4ed8; }
        .plan-card .plan-btn-secondary { background: #f1f5f9; color: #334155; }
        .plan-card .plan-btn-secondary:hover { background: #e2e8f0; }
        .plan-trial-notice { font-size: 13px; color: #64748b; margin-top: 18px; background: #f8fafc; border-radius: 10px; padding: 10px 14px; text-align: center; }
    </style>
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
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Choose your plan</h1>
                <p class="mt-3 text-base text-slate-500 max-w-lg mx-auto">Select a plan that fits your business. Start with a free trial — no payment required today.</p>
            </div>

            @php $hasTabs = $monthlyPlans->isNotEmpty() && $yearlyPlans->isNotEmpty(); @endphp

            <div x-data="{ billingCycle: 'monthly' }">
                {{-- Billing cycle tabs --}}
                @if($hasTabs)
                <div class="flex items-center justify-center gap-1 bg-slate-100 rounded-xl p-1 w-fit mx-auto mb-8">
                    <button
                        @click="billingCycle = 'monthly'"
                        :class="billingCycle === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="px-6 py-2.5 text-sm font-semibold rounded-lg transition-all">
                        Monthly
                    </button>
                    <button
                        @click="billingCycle = 'yearly'"
                        :class="billingCycle === 'yearly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        class="px-6 py-2.5 text-sm font-semibold rounded-lg transition-all flex items-center gap-1.5">
                        Yearly
                        @if($yearlySavingsPercent !== null && $yearlySavingsPercent > 0)
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Save {{ $yearlySavingsPercent }}%</span>
                        @endif
                    </button>
                </div>
                @endif

                {{-- Monthly plans --}}
                <div x-show="!{{ $hasTabs ? 'true' : 'false' }} || billingCycle === 'monthly'"
                     class="{{ $monthlyPlans->count() === 1 ? 'max-w-md mx-auto' : 'grid grid-cols-1 md:grid-cols-3 gap-6 mb-10' }}">
                    @forelse($monthlyPlans as $plan)
                        @include('auth.business._plan-card', ['plan' => $plan])
                    @empty
                        @if(!$hasTabs)
                        <div class="text-center py-12 text-slate-400">
                            <p class="text-lg font-medium mb-2">No plans available</p>
                            <p class="text-sm">Please contact support to set up subscription plans.</p>
                        </div>
                        @endif
                    @endforelse
                </div>

                {{-- Yearly plans --}}
                @if($hasTabs)
                <div x-show="billingCycle === 'yearly'" x-cloak
                     class="{{ $yearlyPlans->count() === 1 ? 'max-w-md mx-auto' : 'grid grid-cols-1 md:grid-cols-3 gap-6 mb-10' }}">
                    @forelse($yearlyPlans as $plan)
                        @include('auth.business._plan-card', ['plan' => $plan])
                    @empty
                    <div class="text-center py-12 text-slate-400">
                        <p class="text-lg font-medium mb-2">No yearly plans available</p>
                    </div>
                    @endforelse
                </div>
                @endif
            </div>
        </div>
    </main>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
