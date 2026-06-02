@extends('management.layout')
@section('subtitle', $location->name)

@section('content')
<x-management.page-header :title="$location->name" subtitle="Location Code: {{ $location->location_code }}">
    <x-slot:actions><x-management.status-badge :status="$location->is_active ? 'active' : 'inactive'" /></x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <x-management.card header="Location Details">
            <div class="grid grid-cols-2 gap-4">
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Address</span><p class="text-sm text-slate-800 mt-0.5">{{ $location->address ?? '—' }}</p></div>
                <div><span class="text-xs text-slate-400 uppercase tracking-wider">Location</span><p class="text-sm text-slate-800 mt-0.5">{{ collect([$location->city, $location->state, $location->country])->filter()->join(', ') ?: '—' }}</p></div>
            </div>
        </x-management.card>

        <x-management.card header="Warehouses at this Location">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($location->warehouses as $wh)
                <a href="{{ route('management.warehouses.show', $wh) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ $wh->name }}</p>
                        <p class="text-xs text-slate-400">{{ $wh->sections->count() }} sections · {{ $wh->stockLocations->sum('quantity') }} items</p>
                    </div>
                    <x-management.status-badge :status="$wh->is_active ? 'active' : 'inactive'" />
                </a>
                @empty
                <div class="px-5 py-6 text-center text-sm text-slate-400">No warehouses at this location yet</div>
                @endforelse
            </div>
        </x-management.card>
    </div>
    <div class="space-y-4">
        <a href="{{ route('management.warehouses.create') }}" class="block w-full text-center py-2.5 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">+ Add Warehouse</a>
        <a href="{{ route('management.locations.edit', $location) }}" class="block w-full text-center py-2.5 border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50">Edit Location</a>
    </div>
</div>
@endsection
