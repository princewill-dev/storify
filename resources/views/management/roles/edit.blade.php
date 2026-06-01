@extends('management.layout')
@section('subtitle', 'Edit Role')

@section('content')
<x-management.page-header title="Edit: {{ $role->name }}" subtitle="Modify permissions for this role" />

@php
    $rolePermissionNames = $role->permissions->pluck('name')->toArray();
@endphp

<div>
    <form action="{{ route('management.roles.update', $role) }}" method="POST" class="space-y-6">
        @csrf @method('PUT')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-management.form-input name="name" label="Role Name" :value="old('name', $role->name)" placeholder="Store Manager" required :error="$errors->first('name')" />
            <x-management.form-input name="description" label="Description" :value="old('description', $role->description)" placeholder="Brief description of this role..." />
        </div>

        <div>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-slate-800">Permissions</h3>
                <div class="flex items-center gap-3 text-xs text-slate-500">
                    <button type="button" onclick="document.querySelectorAll('.perm-checkbox').forEach(c => c.checked = true)" class="hover:text-slate-800 font-medium">Select All</button>
                    <span class="text-slate-300">|</span>
                    <button type="button" onclick="document.querySelectorAll('.perm-checkbox').forEach(c => c.checked = false)" class="hover:text-slate-800 font-medium">Clear All</button>
                </div>
            </div>

            <div class="space-y-3">
                @foreach($availablePermissions as $group => $permissions)
                @php
                    $groupLabel = ucfirst($group);
                    $groupChecked = $permissions->every(fn($p) => in_array($p->name, $rolePermissionNames));
                    $groupId = 'perm-group-' . Str::slug($group);
                @endphp
                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 bg-slate-50 cursor-pointer select-none" onclick="this.parentElement.querySelector('.perm-body').classList.toggle('hidden')">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4 text-slate-400 transition-transform perm-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <span class="text-sm font-medium text-slate-700">{{ $groupLabel }}</span>
                            <span class="text-xs text-slate-400">{{ $permissions->count() }} permissions</span>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer" onclick="event.stopPropagation()">
                            <input type="checkbox"
                                   class="rounded border-slate-300 text-slate-900 focus:ring-slate-500 group-toggle"
                                   data-group="{{ $groupId }}"
                                   {{ $groupChecked ? 'checked' : '' }}
                                   onchange="toggleGroup(this)">
                            <span class="text-xs text-slate-500">All</span>
                        </label>
                    </div>
                    <div class="perm-body border-t border-slate-100 px-4 py-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2">
                            @foreach($permissions as $perm)
                            @php
                                $actionName = substr($perm->name, strlen($group) + 1);
                                $actionLabel = ucfirst(str_replace('_', ' ', $actionName));
                            @endphp
                            <label class="flex items-center gap-2.5 py-1 cursor-pointer group">
                                <input type="checkbox"
                                       name="permissions[]"
                                       value="{{ $perm->name }}"
                                       class="perm-checkbox rounded border-slate-300 text-slate-900 focus:ring-slate-500 {{ $groupId }}"
                                       {{ in_array($perm->name, $rolePermissionNames) ? 'checked' : '' }}>
                                <span class="text-sm text-slate-600 group-hover:text-slate-900">{{ $actionLabel }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
            <a href="{{ route('management.roles.index') }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
        </div>
    </form>
</div>

<script>
function toggleGroup(el) {
    const groupClass = el.dataset.group;
    document.querySelectorAll('.' + groupClass).forEach(cb => {
        cb.checked = el.checked;
    });
}
</script>
@endsection
