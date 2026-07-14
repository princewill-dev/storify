@extends('management.layout')
@section('subtitle', 'Stores')

@section('content')
<div x-data="storeManager()" @store-edit="editing = $event.detail" @store-delete="deleting = $event.detail">
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Stores" subtitle="Manage your storefronts">
    <x-slot:actions>
        <a href="{{ route('management.stores.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Create Store
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
    @forelse($stores ?? [] as $store)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-md transition-shadow">
        {{-- Header --}}
        <div class="px-5 py-4 border-b border-slate-100">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-blue-50 text-blue-600 overflow-hidden shrink-0">
                        @if($store->logoUrl())
                            <img src="{{ $store->logoUrl() }}" alt="{{ $store->name }}" class="w-full h-full object-cover">
                        @else
                            <i class="fi fi-rr-shop text-lg"></i>
                        @endif
                    </span>
                    <div class="min-w-0">
                        <a href="{{ route('management.stores.show', $store) }}" class="text-sm font-semibold text-slate-900 hover:text-blue-600 transition-colors truncate block">{{ $store->name }}</a>
                        <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $store->store_id }}</p>
                    </div>
                </div>
                <x-management.status-badge :status="$store->status" />
            </div>
        </div>

        {{-- Body --}}
        <div class="px-5 py-3 space-y-2">
            @if($store->description)
            <p class="text-xs text-slate-500 line-clamp-2">{{ $store->description }}</p>
            @endif
            @if($store->location)
            <div class="flex items-center gap-1.5 text-xs text-slate-500">
                <i class="fi fi-rr-marker text-slate-400"></i> {{ $store->location }}
            </div>
            @endif
            <div class="flex items-center gap-4 text-xs text-slate-400 pt-1">
                <span class="inline-flex items-center gap-1"><i class="fi fi-rr-apps"></i> {{ $store->categories_count ?? 0 }} categories</span>
                <span class="inline-flex items-center gap-1"><i class="fi fi-rr-cube"></i> {{ $store->products_count ?? 0 }} products</span>
            </div>
        </div>

        {{-- Footer Actions --}}
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex items-center gap-2">
            <a href="{{ route('management.stores.show', $store) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 hover:border-slate-300 transition-colors">
                <i class="fi fi-rr-eye"></i> View
            </a>
            <a href="{{ route('management.stores.orders', $store) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 hover:border-slate-300 transition-colors">
                <i class="fi fi-rr-shopping-cart"></i> Orders
            </a>
            @if($store->has_website)
            <a href="{{ $store->slug ? route('home.store.products.index', ['store_subdomain' => $store->slug]) : '#' }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-medium text-blue-600 hover:text-blue-700 hover:border-blue-300 transition-colors">
                <i class="fi fi-rr-globe"></i> Storefront
            </a>
            @endif
            <div class="flex-1"></div>
            <a href="{{ route('management.stores.show', $store) }}#settings" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 hover:text-slate-600 hover:bg-slate-200 transition-colors">
                <i class="fi fi-rr-settings"></i>
            </a>
        </div>
    </div>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-shop" title="No stores yet" description="Create your first store to start selling online." action-label="Create Store" action-url="{{ route('management.stores.create') }}" />
    </div>
    @endforelse
</div>
</div>
@push('scripts')
<script>document.addEventListener('alpine:init',()=>{Alpine.data('storeManager',()=>({editing:null,deleting:null}))});</script>
@endpush
@endsection
