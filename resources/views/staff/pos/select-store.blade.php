<!DOCTYPE html>
<html lang="en">
<head>
    <title>Select Store · POS</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <span class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-slate-900 text-white mb-4">
            <i class="fi fi-rr-shop text-2xl"></i>
        </span>
        <h1 class="text-xl font-bold text-slate-800">Welcome, {{ $user->name }}</h1>
        <p class="text-sm text-slate-500 mt-1">Select a store to start your POS session</p>
    </div>

    <div class="space-y-3">
        @foreach($assignedStores as $store)
        <form method="POST" action="{{ route('pos.switch-store') }}">
            @csrf
            <input type="hidden" name="store_id" value="{{ $store->id }}">
            <button type="submit" class="w-full bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-4 hover:border-slate-300 hover:shadow-sm transition-all text-left group">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
                    @if($store->logoUrl())
                        <img src="{{ $store->logoUrl() }}" alt="{{ $store->name }}" class="w-full h-full object-cover">
                    @else
                        <i class="fi fi-rr-shop text-slate-400 text-lg"></i>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800 group-hover:text-slate-900">{{ $store->name }}</p>
                    <p class="text-xs text-slate-400 truncate">{{ $store->store_id }}</p>
                </div>
                <i class="fi fi-rr-arrow-right text-slate-300 group-hover:text-slate-500"></i>
            </button>
        </form>
        @endforeach
    </div>

    <div class="mt-6 text-center">
        <form method="POST" action="{{ route('management.auth.logout') }}">
            @csrf
            <button type="submit" class="text-sm text-slate-400 hover:text-slate-600">Logout</button>
        </form>
    </div>
</div>
</body>
</html>
