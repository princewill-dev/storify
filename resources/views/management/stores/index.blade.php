@extends('management.layout')
@section('subtitle', 'Stores')

@section('content')
<x-management.page-header title="Stores" subtitle="Manage your storefronts">
    <x-slot:actions>
        <a href="{{ route('management.stores.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Create Store
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($stores ?? [] as $store)
    <a href="{{ route('management.stores.show', $store) }}" class="block bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:border-blue-300 hover:shadow transition-all">
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-blue-50 text-blue-600 overflow-hidden">
                    @if($store->logoUrl())
                        <img src="{{ $store->logoUrl() }}" alt="{{ $store->name }}" class="w-full h-full object-cover">
                    @else
                        <i class="fi fi-rr-shop"></i>
                    @endif
                </span>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">{{ $store->name }}</h3>
                    <p class="text-xs text-slate-400">{{ $store->store_id }}</p>
                </div>
            </div>
            <x-management.status-badge :status="$store->status" />
        </div>
        @if($store->description)
            <p class="text-xs text-slate-500 line-clamp-2 mb-3">{{ $store->description }}</p>
        @endif
        <div class="flex items-center gap-3 text-xs text-slate-400">
            <span><i class="fi fi-rr-box mr-1"></i> {{ $store->categories->count() }} categories</span>
            <span><i class="fi fi-rr-cube mr-1"></i> {{ \App\Models\Product::where('store_id', $store->id)->count() }} products</span>
        </div>
    </a>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-shop" title="No stores yet" description="Create your first store to start selling online." action-label="Create Store" action-url="{{ route('management.stores.create') }}" />
    </div>
    @endforelse
</div>
@endsection
