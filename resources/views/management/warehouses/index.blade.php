@extends('management.layout')
@section('subtitle', 'Warehouses')

@section('content')
<div x-data="warehouseManager()">
<x-management.page-header title="Warehouses" subtitle="Manage inventory storage locations">
    <x-slot:actions>
        <button @click="showCreateModal = true" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Warehouse
        </button>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($warehouses as $warehouse)
    <div class="relative group bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow hover:border-slate-300 transition-all">
        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="absolute inset-0 z-0" aria-label="{{ $warehouse->name }}"></a>
        <div class="flex items-start justify-between mb-3 relative z-10">
            <div class="flex items-center gap-2.5 min-w-0 pointer-events-none">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl {{ $warehouse->is_active ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-400' }} shrink-0">
                    <i class="fi fi-rr-warehouse-alt"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-slate-800 truncate">{{ $warehouse->name }}</h3>
                    <p class="text-xs text-slate-400">{{ $warehouse->warehouse_code }}</p>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0 pointer-events-auto">
                <x-management.status-badge :status="$warehouse->is_active ? 'active' : 'inactive'" />
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
                        <button onclick="editWarehouse(@js($warehouse->only(['id','warehouse_code','name','contact_person','contact_phone','is_active'])))" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 w-full text-left">
                            <i class="fi fi-rr-edit w-4 text-slate-400"></i> Edit
                        </button>
                        <button onclick="deleteWarehouse(@js($warehouse->only(['id','warehouse_code','name'])))" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left">
                            <i class="fi fi-rr-trash w-4"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="relative z-10">
            @if($warehouse->location)<p class="text-xs text-slate-500 mb-2"><i class="fi fi-rr-marker mr-1 opacity-50"></i>{{ $warehouse->location->name }}</p>@endif
            <div class="flex items-center gap-3 text-xs text-slate-400">
                <span>{{ $warehouse->stockLocations->sum('quantity') }} items</span>
                <span>{{ $warehouse->sections->count() }} sections</span>
            </div>
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
                <x-management.form-input name="name" label="Warehouse Name" required />
                <x-management.form-input name="contact_person" label="Contact Person" />
                <x-management.form-input name="contact_phone" label="Contact Phone" />
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-slate-900">
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
<script>window.editWarehouse=function(d){var e=document.querySelector('[x-data="warehouseManager"]');if(e)Alpine.$data(e).editing=d};window.deleteWarehouse=function(d){var e=document.querySelector('[x-data="warehouseManager"]');if(e)Alpine.$data(e).deleting=d};document.addEventListener('alpine:init',()=>{Alpine.data('warehouseManager',()=>({editing:null,deleting:null}))});</script>
@endpush
@endsection
