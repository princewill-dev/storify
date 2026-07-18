<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — Storify</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    @stack('styles')
</head>
<body class="h-full flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <a href="{{ route('home.index') }}" class="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-600 mb-6 transition-colors">← Back to Home</a>
        @yield('content')
    </div>
</body>
</html>
