@extends('management.layout')
@section('subtitle', 'Warehouses')

@section('content')
<div x-data="warehouseManager()" @warehouse-edit="editing = $event.detail" @warehouse-delete="deleting = $event.detail">
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Warehouses" subtitle="Manage inventory storage locations">
    <x-slot:actions>
        <a href="{{ route('management.warehouses.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Warehouse
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
    @forelse($warehouses as $warehouse)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-md transition-shadow">
        {{-- Header --}}
        <div class="px-5 py-4 border-b border-slate-100">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-xl {{ $warehouse->isActive() ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' }} shrink-0">
                        <i class="fi fi-rr-warehouse-alt text-lg"></i>
                    </span>
                    <div class="min-w-0">
                        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="text-sm font-semibold text-slate-900 hover:text-blue-600 transition-colors truncate block">{{ $warehouse->name }}</a>
                        <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $warehouse->warehouse_code }}</p>
                    </div>
                </div>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $warehouse->isActive() ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-500 ring-slate-500/20' }}">
                    {{ $warehouse->isActive() ? 'Active' : 'Inactive' }}
                </span>
            </div>
        </div>

        {{-- Body --}}
        <div class="px-5 py-3 space-y-2">
            @if($warehouse->location)
            <div class="flex items-center gap-1.5 text-xs text-slate-500">
                <i class="fi fi-rr-marker text-slate-400"></i> {{ $warehouse->location->name }}
            </div>
            @endif
            @if($warehouse->contact_person)
            <div class="flex items-center gap-1.5 text-xs text-slate-500">
                <i class="fi fi-rr-user text-slate-400"></i> {{ $warehouse->contact_person }}
                @if($warehouse->contact_phone)<span class="text-slate-400">· {{ $warehouse->contact_phone }}</span>@endif
            </div>
            @endif
            <div class="flex items-center gap-4 text-xs text-slate-400 pt-1">
                <span class="inline-flex items-center gap-1"><i class="fi fi-rr-cube"></i> {{ $warehouse->sections->count() }} sections</span>
                <span class="inline-flex items-center gap-1"><i class="fi fi-rr-box-alt"></i> {{ $warehouse->stockLocations->sum('quantity') }} items</span>
            </div>
        </div>

        {{-- Footer Actions --}}
        <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 flex items-center gap-2">
            <a href="{{ route('management.warehouses.show', $warehouse) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 hover:border-slate-300 transition-colors">
                <i class="fi fi-rr-eye"></i> View
            </a>
            <a href="{{ route('management.sections.index', $warehouse) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 hover:border-slate-300 transition-colors">
                <i class="fi fi-rr-cube"></i> Sections
            </a>
            <div class="flex-1"></div>
            <button @click.stop="$dispatch('warehouse-edit', { id: '{{ $warehouse->id }}', warehouse_code: '{{ $warehouse->warehouse_code }}', name: '{{ addslashes($warehouse->name) }}', contact_person: '{{ addslashes($warehouse->contact_person ?? '') }}', contact_phone: '{{ $warehouse->contact_phone ?? '' }}', is_active: {{ $warehouse->isActive() ? 'true' : 'false' }} })" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 hover:text-slate-600 hover:bg-slate-200 transition-colors">
                <i class="fi fi-rr-edit"></i>
            </button>
            <button @click.stop="$dispatch('warehouse-delete', { id: '{{ $warehouse->id }}', warehouse_code: '{{ $warehouse->warehouse_code }}', name: '{{ addslashes($warehouse->name) }}' })" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                <i class="fi fi-rr-trash"></i>
            </button>
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
        <div x-show="editing" x-transition class="relative z-10 w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit: <span x-text="editing?.name"></span></h3>
                <button @click="editing = null" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">&times;</button>
            </div>
            <form :action="'/management/warehouses/' + editing?.warehouse_code" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Warehouse Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" :value="editing?.name" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Contact Person</label>
                    <input type="text" name="contact_person" :value="editing?.contact_person" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Contact Phone</label>
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
        <div x-show="deleting" x-transition class="relative z-10 w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
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
