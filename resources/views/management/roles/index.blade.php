@extends('management.layout')
@section('subtitle', 'Roles')

@section('content')
<div x-data="roleManager()">
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Roles & Permissions" subtitle="Define access control for your team">
    <x-slot:actions>
        <a href="{{ route('management.roles.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Create Role
        </a>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($roles as $role)
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex items-start justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-800">{{ $role->name }}</h3>
                @if($role->description)<p class="text-xs text-slate-400 mt-0.5">{{ $role->description }}</p>@endif
            </div>
            <div class="flex items-center gap-1">
                @if($role->is_default)<span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Default</span>@endif
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 z-40 mt-2 w-44 bg-white rounded-xl shadow-lg border border-slate-200 py-1">
                        <a href="{{ route('management.roles.edit', $role) }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50"><i class="fi fi-rr-edit w-4"></i> Edit</a>
                        @if(!$role->is_default)
                        <button onclick="deleteRole(@js($role->only(['id','name'])))" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i> Delete</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-1 mb-3">
            @foreach($role->permissions->take(6) as $perm)
                <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">{{ $perm->name }}</span>
            @endforeach
            @if($role->permissions->count() > 6)
                <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-400">+{{ $role->permissions->count() - 6 }} more</span>
            @endif
        </div>
        <span class="text-xs text-slate-400">{{ $role->users->count() }} members</span>
    </div>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-shield-keyhole" title="No roles yet" description="Custom roles appear after you create them." action-label="Create Role" action-url="{{ route('management.roles.create') }}" />
    </div>
    @endforelse
</div>

{{-- Delete Modal --}}
<div x-show="deleting" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="deleting" x-transition class="fixed inset-0 bg-slate-900/50" @click="deleting = null"></div>
        <div x-show="deleting" x-transition class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Delete Role?</h3>
            <p class="text-sm text-slate-500 mb-4">Delete <strong x-text="deleting?.name"></strong>? Staff assigned to this role will lose those permissions.</p>
            <form :action="'/management/roles/' + deleting?.id" method="POST">
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
<script>window.deleteRole=function(d){var e=document.querySelector('[x-data="roleManager"]');if(e)Alpine.$data(e).deleting=d};document.addEventListener('alpine:init',()=>{Alpine.data('roleManager',()=>({deleting:null}))});</script>
@endpush
@endsection
