@extends('management.layout')
@section('subtitle', $warehouse->name)

@section('content')
<x-management.page-header :title="$warehouse->name" subtitle="Code: {{ $warehouse->warehouse_code }}">
    <x-slot:actions>
        <x-management.status-badge :status="$warehouse->is_active ? 'active' : 'inactive'" />
        @can('transfers create')
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                <i class="fi fi-rr-truck-loading text-xs"></i> Move Inventory
                <i class="fi fi-rr-angle-small-down text-xs"></i>
            </button>
            <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50">
                <a href="{{ route('management.warehouses.send', $warehouse) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fi fi-rr-paper-plane w-4 text-indigo-500"></i> Send Inventory
                </a>
                <a href="{{ route('management.warehouses.receive', $warehouse) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fi fi-rr-truck-loading w-4 text-indigo-500"></i> Receive Inventory
                </a>
            </div>
        </div>
        @endcan
    </x-slot:actions>
</x-management.page-header>

{{-- Tab Bar --}}
<div class="flex items-center gap-1 mb-6 border-b border-slate-200">
    <a href="{{ route('management.warehouses.show', $warehouse) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('management.warehouses.show') && !request()->routeIs('management.warehouses.edit') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }} -mb-px transition-colors">Overview</a>
    <a href="{{ route('management.sections.index', $warehouse) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('management.sections.*') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }} -mb-px transition-colors">Sections</a>
    <a href="{{ route('management.warehouses.edit', $warehouse) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 {{ request()->routeIs('management.warehouses.edit') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }} -mb-px transition-colors">Settings</a>
</div>

{{-- Metric Cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card
        :value="$warehouse->stockLocations->sum('quantity')"
        label="Total Stock"
        icon="fi-rr-warehouse-alt" />

    <x-management.metric-card
        :value="$warehouse->sections->count()"
        label="Sections"
        :subtitle="$warehouse->sections->where('is_active', true)->count() . ' active'"
        icon="fi-rr-cube" />

    <x-management.metric-card
        :value="$lowStockCount"
        label="Low Stock"
        :class="$lowStockCount > 0 ? 'border-amber-200' : ''"
        :subtitle="$lowStockCount > 0 ? 'Needs restock' : 'All stocked'"
        icon="fi-rr-exclamation-triangle" />

    <x-management.metric-card
        :value="$warehouse->assignedStaff->count()"
        label="Assigned Staff"
        subtitle="Team members"
        icon="fi-rr-users-alt" />
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Sections --}}
        <x-management.card header="Sections">
            <div class="-mx-5 -mb-5">
                @forelse($warehouse->sections as $section)
                <a href="{{ route('management.sections.show', [$warehouse, $section]) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors border-b border-slate-50 last:border-b-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg {{ $section->is_active ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400' }} shrink-0">
                            <i class="fi fi-rr-cube text-xs"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $section->name }}</p>
                            <p class="text-xs text-slate-400">{{ $section->section_code }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <span class="text-xs text-slate-400">{{ $section->products_count ?? $section->products->count() }} products</span>
                        <x-management.status-badge :status="$section->is_active ? 'active' : 'inactive'" />
                        <i class="fi fi-rr-angle-small-right text-slate-300"></i>
                    </div>
                </a>
                @empty
                <div class="px-5 py-8 text-center">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3">
                        <i class="fi fi-rr-cube"></i>
                    </span>
                    <p class="text-sm font-medium text-slate-700 mb-1">No sections yet</p>
                    <p class="text-xs text-slate-400 mb-3">Organize your warehouse into physical zones</p>
                    <a href="{{ route('management.sections.create', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-slate-900 rounded-lg hover:bg-slate-800 transition-colors">
                        <i class="fi fi-rr-plus"></i> Create Section
                    </a>
                </div>
                @endforelse
            </div>
            @if($warehouse->sections->isNotEmpty())
            <div class="px-5 py-2.5 border-t border-slate-100 -mx-5 -mb-5 bg-slate-50">
                <a href="{{ route('management.sections.index', $warehouse) }}" class="text-xs font-medium text-slate-600 hover:text-slate-800">View all sections <i class="fi fi-rr-arrow-right text-[10px] ml-0.5"></i></a>
            </div>
            @endif
        </x-management.card>

        {{-- Stock Overview --}}
        <x-management.card header="Stock Overview">
            @if($warehouse->stockLocations->isNotEmpty())
            <div class="-mx-5 -mb-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="text-left px-5 py-2.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Product</th>
                            <th class="text-left px-5 py-2.5 text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Section</th>
                            <th class="text-right px-5 py-2.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Qty</th>
                            <th class="text-right px-5 py-2.5 text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($warehouse->stockLocations->take(10) as $stock)
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3">
                                <p class="text-sm font-medium text-slate-800 truncate max-w-[200px]">{{ $stock->product?->name ?? 'Unknown' }}</p>
                            </td>
                            <td class="px-5 py-3 hidden sm:table-cell">
                                <span class="text-xs text-slate-400">{{ $stock->product?->section?->name ?? '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <span class="text-sm font-semibold {{ $stock->isOutOfStock() ? 'text-red-600' : ($stock->isLowStock() ? 'text-amber-600' : 'text-slate-800') }}">
                                    {{ $stock->quantity }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right hidden sm:table-cell">
                                @if($stock->isOutOfStock())
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600">Out</span>
                                @elseif($stock->isLowStock())
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-600">Low</span>
                                @else
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-600">In Stock</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($warehouse->stockLocations->count() > 10)
            <div class="px-5 py-2.5 border-t border-slate-100 -mx-5 -mb-5 bg-slate-50">
                <span class="text-xs text-slate-400">Showing 10 of {{ $warehouse->stockLocations->count() }} items</span>
            </div>
            @endif
            @else
            <div class="px-5 py-8 text-center">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3">
                    <i class="fi fi-rr-box-alt"></i>
                </span>
                <p class="text-sm font-medium text-slate-700 mb-1">No stock recorded</p>
                <p class="text-xs text-slate-400">Products assigned to this warehouse will appear here</p>
            </div>
            @endif
        </x-management.card>

    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">

        <x-management.card header="Warehouse Info">
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Code</span>
                    <span class="text-sm font-medium text-slate-700 font-mono">{{ $warehouse->warehouse_code }}</span>
                </div>
                @if($warehouse->address)
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Address</span>
                    <span class="text-sm font-medium text-slate-700 text-right max-w-[60%]">{{ $warehouse->address }}</span>
                </div>
                @endif
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Location</span>
                    <span class="text-sm font-medium text-slate-700 text-right">{{ collect([$warehouse->city, $warehouse->state])->filter()->join(', ') ?: '—' }}</span>
                </div>
                @if($warehouse->contact_person)
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Contact</span>
                    <span class="text-sm font-medium text-slate-700 text-right">{{ $warehouse->contact_person }}</span>
                </div>
                @endif
                @if($warehouse->contact_phone)
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Phone</span>
                    <span class="text-sm font-medium text-slate-700">{{ $warehouse->contact_phone }}</span>
                </div>
                @endif
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Created</span>
                    <span class="text-sm font-medium text-slate-700">{{ $warehouse->created_at->format('d M Y') }}</span>
                </div>
            </div>
        </x-management.card>

        <x-management.card>
            <div class="space-y-2">
                <a href="{{ route('management.sections.create', $warehouse) }}" class="block w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">
                    <i class="fi fi-rr-plus mr-1"></i> Add Section
                </a>
                <a href="{{ route('management.warehouses.edit', $warehouse) }}" class="block w-full py-2 bg-white border border-slate-200 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-50 transition-colors text-center">
                    <i class="fi fi-rr-settings mr-1"></i> Edit Warehouse
                </a>
            </div>
        </x-management.card>

    </div>
</div>
@endsection
