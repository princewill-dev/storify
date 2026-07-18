<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <title>Admin · @yield('subtitle', 'Superadmin')</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">

    @vite('resources/css/app.css')

    <style>
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #475569; }
        * { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .sidebar-scroll { scrollbar-color: #334155 transparent; }
    </style>

    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.css') }}">

    @stack('styles')
    @stack('head-scripts')
</head>
<body class="h-full" x-data="{ sidebarOpen: true, mobileMenuOpen: false }">

<div class="flex h-full">

    {{-- Sidebar --}}
    @include('admin.components.sidebar')

    {{-- Main Content --}}
    <div class="flex flex-1 flex-col min-w-0 lg:pl-64" :class="{ 'lg:pl-64': sidebarOpen, 'lg:pl-0': !sidebarOpen }">

        {{-- Header --}}
        @include('admin.components.header')

        {{-- Page Content --}}
        <main class="flex-1 overflow-auto">
            <div class="px-4 sm:px-6 py-6">
                @yield('content')
            </div>
        </main>
    </div>
</div>

{{-- Toast Notifications --}}
@if(session('success'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition class="fixed bottom-4 right-4 z-50 max-w-sm w-full bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl shadow-lg p-4">
    <div class="flex items-start gap-3">
        <i class="fi fi-rr-check-circle text-emerald-500 text-lg mt-0.5"></i>
        <div class="flex-1 text-sm font-medium">{{ session('success') }}</div>
        <button @click="show = false" class="text-emerald-400 hover:text-emerald-600">&times;</button>
    </div>
</div>
@endif
@if(session('error'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 7000)" x-transition class="fixed bottom-4 right-4 z-50 max-w-sm w-full bg-red-50 border border-red-200 text-red-800 rounded-xl shadow-lg p-4">
    <div class="flex items-start gap-3">
        <i class="fi fi-rr-exclamation text-red-500 text-lg mt-0.5"></i>
        <div class="flex-1 text-sm font-medium">{{ session('error') }}</div>
        <button @click="show = false" class="text-red-400 hover:text-red-600">&times;</button>
    </div>
</div>
@endif

<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>function openModal(id){document.getElementById(id).classList.remove('hidden')}function closeModal(id){document.getElementById(id).classList.add('hidden')}</script>

@stack('modals')
@stack('scripts')

</body>
</html>
