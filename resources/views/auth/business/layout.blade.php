<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Storify')</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
    @vite('resources/css/app.css')
    @stack('styles')
</head>
<body class="h-full bg-slate-50 font-sans antialiased">

<div class="flex min-h-full">
    {{-- Left Panel — Branding --}}
    <div class="relative hidden w-3/5 flex-col bg-slate-900 lg:flex">
        {{-- Background pattern --}}
        <div class="absolute inset-0 overflow-hidden opacity-[0.07]">
            <svg class="absolute -top-1/2 -left-1/4 w-[150%] h-[150%]" viewBox="0 0 800 800" fill="none">
                <circle cx="400" cy="400" r="300" stroke="white" stroke-width="0.5" fill="none"/>
                <circle cx="400" cy="400" r="200" stroke="white" stroke-width="0.5" fill="none"/>
                <circle cx="400" cy="400" r="100" stroke="white" stroke-width="0.5" fill="none"/>
                <circle cx="400" cy="400" r="400" stroke="white" stroke-width="0.25" fill="none" stroke-dasharray="4 8"/>
            </svg>
        </div>

        {{-- Content --}}
        <div class="relative flex flex-1 flex-col justify-between p-12">
            <div>
                <div class="flex items-center gap-3 mb-8">
                    <img src="{{ $company->favicon }}" alt="" class="h-8 w-8 rounded-lg">
                    <span class="text-lg font-semibold text-white tracking-tight">{{ $company->name ?? 'Storify' }}</span>
                </div>
                <div class="max-w-sm">
                    <h2 class="text-3xl font-bold text-white tracking-tight leading-tight">
                        @yield('hero_title', 'Manage your business, stores, and team — all in one place.')
                    </h2>
                    <p class="mt-4 text-base text-slate-400 leading-relaxed">
                        @yield('hero_subtitle', 'The modern platform for multi-store retail management. Inventory, POS, staff, orders, and analytics.')
                    </p>
                </div>
            </div>

            {{-- Stats / Trust indicators --}}
            <div class="grid grid-cols-3 gap-6">
                <div>
                    <p class="text-2xl font-bold text-white">@yield('stat_1', 'All-in-one')</p>
                    <p class="text-xs text-slate-500 mt-1">@yield('stat_1_sub', 'Platform')</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-white">@yield('stat_2', 'Multi-store')</p>
                    <p class="text-xs text-slate-500 mt-1">@yield('stat_2_sub', 'Management')</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-white">@yield('stat_3', 'Built-in')</p>
                    <p class="text-xs text-slate-500 mt-1">@yield('stat_3_sub', 'POS + Analytics')</p>
                </div>
            </div>

            <div class="text-xs text-slate-600">
                &copy; {{ date('Y') }} {{ $company->name ?? 'Storify' }}. All rights reserved.
            </div>
        </div>
    </div>

    {{-- Right Panel — Form --}}
    <div class="flex flex-1 flex-col justify-center bg-white">
        {{-- Mobile logo (visible only on small screens) --}}
        <div class="flex items-center justify-between px-8 pt-8 lg:hidden">
            <div class="flex items-center gap-2">
                <img src="{{ $company->favicon }}" alt="" class="h-7 w-7 rounded-lg">
                <span class="text-base font-semibold text-slate-900">{{ $company->name ?? 'Storify' }}</span>
            </div>
            <a href="{{ route('home.index') }}" class="text-xs text-slate-400 hover:text-slate-600 transition-colors">← Home</a>
        </div>

        <div class="w-full max-w-md mx-auto px-6 lg:px-0">
            <a href="{{ route('home.index') }}" class="hidden lg:inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-600 transition-colors -mt-6 mb-4">
                ← Back to Home
            </a>
            @yield('form')
        </div>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@stack('scripts')
</body>
</html>
