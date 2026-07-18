@extends('admin.layout')
@section('subtitle', 'Businesses')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Businesses</h2>
    <div class="flex items-center gap-2">
        <button onclick="openModal('filterVendorsModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            <i class="fi fi-rr-filter text-sm"></i> Filter
        </button>
        <button onclick="openModal('createVendorModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-plus text-sm"></i> Add Business
        </button>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Owner</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Stores</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Warehouses</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Subscription</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($businesses as $business)
                    @php($owner = $business->owner)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.vendors.show', $owner) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $business->name }}</a>
                            <div class="text-xs text-slate-400 font-mono">{{ $business->business_code }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-slate-700">{{ $owner?->name ?? '—' }}</span>
                            <div class="text-xs text-slate-400">{{ $owner?->email ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $business->stores_count }}</span>
                            @if($business->stores_count > 0)
                                <a href="{{ route('admin.stores.index') }}?q={{ $business->name }}" class="text-xs text-indigo-600 hover:underline ml-1">view</a>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $business->warehouses_count }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php($sub = $business->activeSubscription)
                            @if($sub)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ $sub->subscriptionPlan?->name ?? 'Active' }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">None</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php($bizBadge = $vendorStatusBadgeData[strtolower($business->status)] ?? null)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ ($bizBadge['class'] ?? '') ?: 'bg-slate-100 text-slate-600' }}">
                                {{ $bizBadge['label'] ?? ucfirst($business->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right" x-data="{ open: false }">
                            <div class="relative inline-block">
                                <button @click="open = !open" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                                    <i class="fi fi-rr-menu-dots text-sm"></i>
                                </button>
                                <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-44 bg-white rounded-lg shadow-lg border border-slate-200 py-1">
                                    <a href="{{ route('admin.vendors.show', $owner) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                        <i class="fi fi-rr-eye text-slate-400"></i> View
                                    </a>
                                    @if($owner)
                                    <button onclick="editBusiness('{{ route('admin.vendors.update', $owner) }}', '{{ $owner->name }}', '{{ $owner->slug }}', '{{ $owner->email }}', '{{ $owner->phone }}', '{{ $owner->status }}'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                        <i class="fi fi-rr-pencil text-slate-400"></i> Edit Owner
                                    </button>
                                    <button onclick="confirmAction('activateVendorForm', '{{ route('admin.vendors.activate', $owner) }}', '{{ $business->name }}', 'activateVendorName'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                        <i class="fi fi-rr-check-circle text-slate-400"></i> Activate
                                    </button>
                                    <button onclick="confirmAction('suspendVendorForm', '{{ route('admin.vendors.suspend', $owner) }}', '{{ $business->name }}', 'suspendVendorName'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                        <i class="fi fi-rr-ban text-slate-400"></i> Suspend
                                    </button>
                                    <button onclick="confirmDelete('{{ route('admin.vendors.destroy', $owner) }}', '{{ $business->name }}'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left">
                                        <i class="fi fi-rr-trash text-red-400"></i> Delete
                                    </button>
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">No businesses yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-4 py-3 border-t border-slate-100">
        {{ $businesses->links() }}
    </div>
</div>

{{-- Delete Business Modal --}}
<div id="deleteVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="deleteVendorLabel" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Delete Business</h5>
            <form id="deleteVendorForm" method="POST" action="#">
                @csrf
                @method('DELETE')
                <p class="text-sm text-slate-600 mb-3">You're about to delete this business:</p>
                <input type="text" id="deleteVendorName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" disabled>
                <p class="mt-3 text-xs text-red-500">This action cannot be undone.</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="closeModal('deleteVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Create Business Modal --}}
<div id="createVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Add Business</h5>
            <form action="{{ route('admin.vendors.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input type="text" name="name" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ old('name') }}" required>
                </div>
                <div class="hidden">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Slug</label>
                    <input type="text" name="slug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg" value="{{ old('slug') }}" placeholder="auto-generated from name if left blank">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ old('email') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                    <input type="text" name="phone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ old('phone') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="active" {{ old('status','active')=='active'?'selected':'' }}>active</option>
                        <option value="inactive" {{ old('status')=='inactive'?'selected':'' }}>inactive</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('createVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Business Modal --}}
<div id="editVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Edit Business</h5>
            <form id="editVendorForm" action="#" method="POST" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input type="text" name="name" id="editVendorName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                </div>
                <div class="hidden">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Slug</label>
                    <input type="text" name="slug" id="editVendorSlug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg" placeholder="auto-generated from name if left blank">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" id="editVendorEmail" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                    <input type="text" name="phone" id="editVendorPhone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" id="editVendorStatus" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                        <option value="suspended">suspended</option>
                        <option value="deleted">deleted</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('editVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Filter Businesses Modal --}}
<div id="filterVendorsModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('filterVendorsModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Filter Businesses</h5>
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">All</option>
                            <option value="active" @selected(($status ?? '')==='active')>Active</option>
                            <option value="suspended" @selected(($status ?? '')==='suspended')>Suspended</option>
                            <option value="deleted" @selected(($status ?? '')==='deleted')>Deleted</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
                        <input type="text" name="q" value="{{ $q ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Name, email or phone">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">From</label>
                        <input type="date" name="from" value="{{ $from ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">To</label>
                        <input type="date" name="to" value="{{ $to ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('admin.vendors.index') }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Reset</a>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Suspend Business Modal --}}
<div id="suspendVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('suspendVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Suspend Business</h5>
            <form id="suspendVendorForm" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Business</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="suspendVendorName" disabled>
                </div>
                <div>
                    <label for="suspendReason" class="block text-sm font-medium text-slate-700 mb-1">Reason</label>
                    <textarea class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" id="suspendReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('suspendVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600">Suspend</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Activate Business Modal --}}
<div id="activateVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('activateVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Activate Business</h5>
            <form id="activateVendorForm" method="POST" class="space-y-4">
                @csrf
                <div class="px-4 py-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-700">
                    Activating this business will also approve their KYC submission. This action means you are okay with the vendor's KYC submission.
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Business</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="activateVendorName" disabled>
                </div>
                <div>
                    <label for="activateReason" class="block text-sm font-medium text-slate-700 mb-1">Reason / Notes</label>
                    <textarea class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" id="activateReason" name="reason" rows="4" placeholder="Provide reason for activation" required></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('activateVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Activate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmAction(formId, action, displayName, inputId) {
    const form = document.getElementById(formId);
    const input = document.getElementById(inputId);
    if (form) form.action = action;
    if (input) input.value = displayName || '';
    openModal(formId === 'suspendVendorForm' ? 'suspendVendorModal' : 'activateVendorModal');
}

function confirmDelete(action, displayName) {
    const form = document.getElementById('deleteVendorForm');
    const input = document.getElementById('deleteVendorName');
    if (form) form.action = action;
    if (input) input.value = displayName || '';
    openModal('deleteVendorModal');
}

function editBusiness(action, name, slug, email, phone, status) {
    const form = document.getElementById('editVendorForm');
    if (form) form.action = action;
    document.getElementById('editVendorName').value = name || '';
    document.getElementById('editVendorSlug').value = slug || '';
    document.getElementById('editVendorEmail').value = email || '';
    document.getElementById('editVendorPhone').value = phone || '';
    const statusSelect = document.getElementById('editVendorStatus');
    if (statusSelect) {
        Array.from(statusSelect.options).forEach(function(opt) {
            opt.selected = (opt.value.toLowerCase() === (status || '').toLowerCase());
        });
    }
    openModal('editVendorModal');
}
</script>
@endsection
