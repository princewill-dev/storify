@extends('management.layout')
@section('subtitle', 'Warehouses')

@section('content')
<div x-data="warehouseManager()" @warehouse-edit="editing = $event.detail" @warehouse-delete="deleting = $event.detail">
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Warehouses" subtitle="Manage inventory storage locations">
    <x-slot:actions>
        <a href="{{ route('management.warehouses.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Warehouse
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($warehouses as $warehouse)
    <div class="relative group bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow hover:border-slate-300 transition-all">
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl {{ $warehouse->isActive() ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-400' }} shrink-0">

  ...

                <x-management.status-badge :status="$warehouse->isActive() ? 'active' : 'inactive'" />
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click.stop.prevent="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 z-40 mt-2 w-44 bg-white rounded-xl shadow-lg border border-slate-200 py-1">
                        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <i class="fi fi-rr-eye w-4 text-slate-400"></i> View
                        </a>
                        <a href="{{ route('management.sections.index', $warehouse) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <i class="fi fi-rr-cube w-4 text-slate-400"></i> Sections
                        </a>
                        <button @click.stop="$dispatch('warehouse-edit', { id: '{{ $warehouse->id }}', warehouse_code: '{{ $warehouse->warehouse_code }}', name: '{{ addslashes($warehouse->name) }}', contact_person: '{{ addslashes($warehouse->contact_person ?? '') }}', contact_phone: '{{ $warehouse->contact_phone ?? '' }}', is_active: {{ $warehouse->isActive() ? 'true' : 'false' }} })" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 w-full text-left">
                            <i class="fi fi-rr-edit w-4 text-slate-400"></i> Edit
                        </button>
                        <button @click.stop="$dispatch('warehouse-delete', { id: '{{ $warehouse->id }}', warehouse_code: '{{ $warehouse->warehouse_code }}', name: '{{ addslashes($warehouse->name) }}' })" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left">
                            <i class="fi fi-rr-trash w-4"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-2">
            @if($warehouse->location)<p class="text-xs text-slate-500 mb-2"><i class="fi fi-rr-marker mr-1 opacity-50"></i>{{ $warehouse->location->name }}</p>@endif
            <div class="flex items-center gap-3 text-xs text-slate-400">
                <span>{{ $warehouse->stockLocations->sum('quantity') }} items</span>
                <span>{{ $warehouse->sections->count() }} sections</span>
            </div>
            @if($warehouse->assignedStaff->isNotEmpty())
            <div class="mt-2 pt-2 border-t border-slate-100">
                <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-1">Assigned Staff</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($warehouse->assignedStaff->take(4) as $staff)
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">{{ $staff->name }}</span>
                    @endforeach
                    @if($warehouse->assignedStaff->count() > 4)
                    <span class="text-[10px] text-slate-400">+{{ $warehouse->assignedStaff->count() - 4 }} more</span>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-warehouse-alt" title="No warehouses yet" description="Set up inventory storage locations." action-label="Add Warehouse" action-url="{{ route('management.warehouses.create') }}" />
    </div>
    @endforelse
</div>

{{-- Edit Modal --}}
<div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="editing" x-transition class="fixed inset-0 bg-slate-900/50" @click="editing = null"></div>
        <div x-show="editing" x-transition class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit: <span x-text="editing?.name"></span></h3>
                <button @click="editing = null" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">&times;</button>
            </div>
            <form :action="'/management/warehouses/' + editing?.warehouse_code" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Warehouse Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" :value="editing?.name" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Contact Person</label>
                    <input type="text" name="contact_person" :value="editing?.contact_person" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Contact Phone</label>
                    <input type="text" name="contact_phone" :value="editing?.contact_phone" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" :checked="editing?.is_active" class="rounded border-slate-300 text-slate-900">
                    <span class="text-sm text-slate-700">Active</span>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save</button>
                    <button type="button" @click="editing = null" class="text-sm text-slate-500">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Modal --}}
<div x-show="deleting" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="deleting" x-transition class="fixed inset-0 bg-slate-900/50" @click="deleting = null"></div>
        <div x-show="deleting" x-transition class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Delete Warehouse?</h3>
            <p class="text-sm text-slate-500 mb-4">Delete <strong x-text="deleting?.name"></strong>? This cannot be undone.</p>
            <form :action="'/management/warehouses/' + deleting?.warehouse_code" method="POST">
                @csrf @method('DELETE')
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Delete</button>
                    <button type="button" @click="deleting = null" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
@push('scripts')
<script>document.addEventListener('alpine:init',()=>{Alpine.data('warehouseManager',()=>({editing:null,deleting:null}))});</script>
@endpush
@endsection
