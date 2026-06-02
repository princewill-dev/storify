@extends('management.layout')
@section('subtitle', 'POS · ' . $store->name)

@push('styles')
<style>
    .pos-terminal-page { display: flex; flex-direction: column; height: calc(100vh - 120px); }
    .pos-terminal-toolbar { flex-shrink: 0; }
    .pos-terminal-area { flex: 1; min-height: 0; }
    .pos-layout { display: grid; grid-template-columns: 1fr 380px; gap: 0; height: 100%; }
    .pos-products-panel { overflow-y: auto; padding: 1.25rem; background: #f8fafc; }
    .pos-cart-panel { display: flex; flex-direction: column; background: #fff; border-left: 1px solid #e2e8f0; overflow: hidden; }
    .cart-items-scroll { flex: 1; overflow-y: auto; padding: 1rem; min-height: 0; }
    .cart-footer-section { flex-shrink: 0; border-top: 1px solid #e2e8f0; padding: 1rem; }
    .product-card { cursor: pointer; transition: all 0.15s; }
    .product-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .product-card:active { transform: scale(0.98); }
    @media (max-width: 1024px) {
        .pos-terminal-page { height: auto; }
        .pos-layout { grid-template-columns: 1fr; grid-template-rows: 1fr auto; height: auto; max-height: 60vh; }
        .pos-cart-panel { border-left: none; border-top: 1px solid #e2e8f0; }
    }
</style>
@endpush

@section('content')
<div class="pos-terminal-page -m-6">
    <div class="pos-terminal-toolbar flex items-center gap-3 px-4 py-2.5 bg-white border-b">
        <a href="{{ route('management.pos.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Sessions
        </a>
        <div class="h-4 w-px bg-slate-200"></div>
        <span class="text-sm font-semibold text-slate-800">{{ $store->name }}</span>
        <span class="text-xs text-slate-400">{{ $session->session_code }}</span>
        <div class="flex-1"></div>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live
        </span>
    </div>
    <div class="pos-terminal-area">
        @include('management.pos._terminal-body')
    </div>
</div>

@if(session('success'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition class="fixed top-4 right-4 z-50 max-w-sm w-full bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl shadow-lg p-4">
    <div class="flex items-start gap-3"><i class="fi fi-rr-check-circle text-emerald-500 text-lg mt-0.5"></i><div class="flex-1 text-sm font-medium">{{ session('success') }}</div><button @click="show = false" class="text-emerald-400 hover:text-emerald-600">&times;</button></div>
</div>
@endif
@if(session('error'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition class="fixed top-4 right-4 z-50 max-w-sm w-full bg-red-50 border border-red-200 text-red-800 rounded-xl shadow-lg p-4">
    <div class="flex items-start gap-3"><i class="fi fi-rr-exclamation text-red-500 text-lg mt-0.5"></i><div class="flex-1 text-sm font-medium">{{ session('error') }}</div><button @click="show = false" class="text-red-400 hover:text-red-600">&times;</button></div>
</div>
@endif
@endsection
