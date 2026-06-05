@extends('management.layout')
@section('subtitle', 'Locations')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Locations" subtitle="Manage your business sites and branches">
    <x-slot:actions>
        <button onclick="openCreateModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Location
        </button>
    </x-slot:actions>
</x-management.page-header>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @forelse($locations as $loc)
    <div class="group bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:shadow transition-all">
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl {{ $loc->is_active ? 'bg-slate-100 text-slate-600' : 'bg-slate-100 text-slate-400' }}">
                    <i class="fi fi-rr-marker"></i>
                </span>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">{{ $loc->name }}</h3>
                    <p class="text-xs text-slate-400">{{ $loc->location_code }}</p>
                </div>
            </div>
            <div class="flex items-center gap-1">
                <x-management.status-badge :status="$loc->is_active ? 'active' : 'inactive'" />
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 z-40 mt-2 w-44 bg-white rounded-xl shadow-lg border border-slate-200 py-1">
                        <a href="{{ route('management.locations.show', $loc) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <i class="fi fi-rr-eye w-4 text-slate-400"></i> View
                        </a>
                        <button @click="open = false" onclick="openEditModal('{{ $loc->location_code }}', '{{ addslashes($loc->name) }}', '{{ addslashes($loc->address) }}', '{{ addslashes($loc->city) }}', '{{ addslashes($loc->state) }}', {{ $loc->is_active ? 'true' : 'false' }})" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 w-full text-left">
                            <i class="fi fi-rr-edit w-4 text-slate-400"></i> Edit
                        </button>
                        <button @click="open = false" onclick="openDeleteModal('{{ $loc->location_code }}', '{{ addslashes($loc->name) }}')" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left">
                            <i class="fi fi-rr-trash w-4"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @if($loc->address || $loc->city)
        <p class="text-xs text-slate-500 mb-3">{{ collect([$loc->address, $loc->city, $loc->state])->filter()->join(', ') }}</p>
        @endif
        <span class="text-xs text-slate-400">{{ $loc->warehouses_count }} warehouses</span>
    </div>
    @empty
    <div class="col-span-full">
        <x-management.empty-state icon="fi fi-rr-marker" title="No locations yet" action-label="Add Location" :action-url="route('management.locations.create')" />
    </div>
    @endforelse
</div>

{{-- Create Modal --}}
<div id="createModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createModal')"></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Add Location</h3>
                <button onclick="closeModal('createModal')" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">&times;</button>
            </div>
            <form action="{{ route('management.locations.store') }}" method="POST" class="p-6 space-y-4">
                @csrf
                <x-management.form-input name="name" label="Location Name" placeholder="Main Branch" required />
                <x-management.form-input name="address" label="Address" placeholder="Street address" />
                <div class="grid grid-cols-3 gap-4">
                    <input type="hidden" name="country" value="Nigeria">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Country</label><p class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">Nigeria</p></div>
                    <x-management.form-input name="state" label="State" type="select">
                        <option value="">Select</option>
                        @foreach($nigerianStates as $abbr => $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                    </x-management.form-input>
                    <x-management.form-input name="city" label="City" type="select">
                        <option value="">Select</option>
                        @foreach($nigerianCities as $city)<option value="{{ $city }}">{{ $city }}</option>@endforeach
                    </x-management.form-input>
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-slate-900">
                    <span class="text-sm text-slate-700">Active</span>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Create</button>
                    <button type="button" onclick="closeModal('createModal')" class="text-sm text-slate-500 hover:text-slate-700">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Modal --}}
<div id="editModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editModal')"></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit: <span id="editName"></span></h3>
                <button onclick="closeModal('editModal')" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">&times;</button>
            </div>
            <form id="editForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <x-management.form-input name="name" label="Location Name" required />
                <x-management.form-input name="address" label="Address" placeholder="Street address" />
                <div class="grid grid-cols-3 gap-4">
                    <input type="hidden" name="country" value="Nigeria">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1.5">Country</label><p class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-500">Nigeria</p></div>
                    <x-management.form-input name="state" label="State" type="select">
                        <option value="">Select</option>
                        @foreach($nigerianStates as $abbr => $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                    </x-management.form-input>
                    <x-management.form-input name="city" label="City" type="select">
                        <option value="">Select</option>
                        @foreach($nigerianCities as $city)<option value="{{ $city }}">{{ $city }}</option>@endforeach
                    </x-management.form-input>
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="editActive" name="is_active" value="1" class="rounded border-slate-300 text-slate-900">
                    <span class="text-sm text-slate-700">Active</span>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Save Changes</button>
                    <button type="button" onclick="closeModal('editModal')" class="text-sm text-slate-500 hover:text-slate-700">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Modal --}}
<div id="deleteModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteModal')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Delete Location?</h3>
            <p class="text-sm text-slate-500 mb-4">Are you sure you want to delete <strong id="deleteName"></strong>? This cannot be undone.</p>
            <form id="deleteForm" method="POST">
                @csrf @method('DELETE')
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Delete</button>
                    <button type="button" onclick="closeModal('deleteModal')" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function openCreateModal() { openModal('createModal'); }
function openEditModal(code, name, address, city, state, isActive) {
    document.getElementById('editName').textContent = name;
    document.getElementById('editForm').action = '/management/locations/' + code;
    document.querySelector('#editForm [name="name"]').value = name || '';
    document.querySelector('#editForm [name="address"]').value = address || '';
    document.querySelector('#editForm [name="city"]').value = city || '';
    document.querySelector('#editForm [name="state"]').value = state || '';
    document.getElementById('editActive').checked = isActive;
    openModal('editModal');
}
function openDeleteModal(code, name) {
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteForm').action = '/management/locations/' + code;
    openModal('deleteModal');
}
</script>
@endpush
@endsection
