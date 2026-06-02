@extends('management.layout')
@section('subtitle', 'Categories')

@section('content')
<div x-data="catManager()">
<x-management.page-header title="Categories" subtitle="Organize products by category">
    <x-slot:actions>
        <button @click="showCreateModal = true" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Category
        </button>
    </x-slot:actions>
</x-management.page-header>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Name</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Slug</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Products</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>
    @forelse($categories as $cat)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3"><span class="text-sm font-medium text-slate-800">{{ $cat->name }}</span></td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-xs text-slate-400 font-mono">{{ $cat->slug }}</span></td>
        <td class="px-5 py-3 text-center">
            <span class="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 text-sm font-semibold rounded-full bg-blue-50 text-blue-600">{{ $cat->products_count }}</span>
        </td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$cat->status" /></td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <button @click="editCategory({{ $cat->id }})" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </button>
                <button @click="deleteCategory({{ $cat->id }})" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete">
                    <i class="fi fi-rr-trash text-xs"></i>
                </button>
            </div>
        </td>
    </tr>
    @empty
    <tr><td colspan="5" class="px-5 py-12"><x-management.empty-state icon="fi fi-rr-list" title="No categories" description="Create categories to organize products." action-label="Add Category" action-url="{{ route('management.categories.create') }}" /></td></tr>
    @endforelse
</x-management.data-table>

{{-- Edit Modal --}}
<div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="editing" x-transition class="fixed inset-0 bg-slate-900/50" @click="editing = null"></div>
        <div x-show="editing" x-transition class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit: <span x-text="editing?.name"></span></h3>
                <button @click="editing = null" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form :action="'/management/categories/' + editing?.id" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" x-bind:value="editing?.name" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Store</label>
                    <select name="store_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}" :selected="editing?.store_id == {{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Status</label>
                    <select name="status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                        <option value="active" :selected="editing?.status === 'active'">Active</option>
                        <option value="inactive" :selected="editing?.status === 'inactive'">Inactive</option>
                    </select>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                    <button type="button" @click="editing = null" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</button>
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
            <h3 class="text-base font-semibold text-slate-800 mb-2">Delete Category?</h3>
            <p class="text-sm text-slate-500 mb-4">Delete <strong x-text="deleting?.name"></strong>?</p>
            <form :action="'/management/categories/' + deleting?.id" method="POST">
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
<script>document.addEventListener('alpine:init',()=>{Alpine.data('catManager',()=>({showCreateModal:false,editing:null,deleting:null,categories:{!! json_encode($categories->items() ? collect($categories->items())->map->only(['id','name','status','store_id']) : []) !!},editCategory(id){this.editing=this.categories.find(c=>c.id===id)||null},deleteCategory(id){this.deleting=this.categories.find(c=>c.id===id)||null}}))});</script>
@endpush
@endsection
