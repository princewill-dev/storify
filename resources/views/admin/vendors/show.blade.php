@extends('admin.layout')
@section('subtitle', $business ? $business->name : $user->name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">
            {{ $business?->name ?? $user->name }}
            @if($business)<span class="text-sm font-normal text-slate-400 font-mono ml-2">{{ $business->business_code }}</span>@endif
        </h2>
        @if($business?->prefix)<p class="text-xs text-slate-400 mt-0.5">Prefix: {{ $business->prefix }}</p>@endif
    </div>
    <div class="flex items-center gap-2">
        <button onclick="editVendorModal('{{ $user->account_id }}', '{{ $user->name }}', '{{ $user->slug }}', '{{ $user->email }}', '{{ $user->phone }}', '{{ $user->status }}')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-pencil text-sm"></i> Edit Owner
        </button>
        <button onclick="suspendVendor('{{ $user->account_id }}', '{{ $business?->name ?? $user->name }}')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100">
            <i class="fi fi-rr-ban text-sm"></i> Suspend
        </button>
        <button onclick="deleteVendor('{{ $user->account_id }}', '{{ $business?->name ?? $user->name }}')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-red-200 bg-red-50 text-red-700 hover:bg-red-100">
            <i class="fi fi-rr-trash text-sm"></i> Delete
        </button>
    </div>
</div>

@if($business)
{{-- Summary cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 text-center">
        <div class="text-2xl font-bold text-slate-900">{{ $business->stores_count }}</div>
        <div class="text-xs text-slate-500 mt-1">Stores</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 text-center">
        <div class="text-2xl font-bold text-slate-900">{{ $business->warehouses_count }}</div>
        <div class="text-xs text-slate-500 mt-1">Warehouses</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 text-center">
        <div class="text-2xl font-bold text-slate-900">{{ $business->users_count }}</div>
        <div class="text-xs text-slate-500 mt-1">Team Members</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 text-center">
        @php($sub = $business->activeSubscription)
        @if($sub)
            <div class="text-lg font-bold text-emerald-600">{{ $sub->subscriptionPlan?->name ?? 'Active' }}</div>
            <div class="text-xs text-slate-500 mt-1">{{ $sub->ends_at ? 'Until ' . $sub->ends_at->format('d M Y') : 'Active' }}</div>
        @else
            <div class="text-lg font-bold text-slate-400">No Plan</div>
            <div class="text-xs text-slate-500 mt-1">Subscription</div>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Business & Owner details --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Business Details</h3>
        </div>
        <div class="px-5 py-4 space-y-3">
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Name</span>
                <span class="text-sm font-medium text-slate-900">{{ $business->name }}</span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Code</span>
                <code class="text-xs text-slate-700 bg-slate-50 px-2 py-0.5 rounded">{{ $business->business_code }}</code>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Status</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $business->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($business->status === 'suspended' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600') }}">
                    {{ $business->status }}
                </span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Created</span>
                <span class="text-sm text-slate-700">{{ $business->created_at->format('d M Y, H:i') }}</span>
            </div>

            <div class="border-t border-slate-100 pt-4 mt-4">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Owner</h3>
                <div class="space-y-3">
                    <div class="flex">
                        <span class="w-32 text-xs text-slate-500 shrink-0">Name</span>
                        <span class="text-sm text-slate-900">{{ $business->owner?->name ?? '—' }}</span>
                    </div>
                    <div class="flex">
                        <span class="w-32 text-xs text-slate-500 shrink-0">Email</span>
                        <span class="text-sm text-slate-700">{{ $business->owner?->email ?? '—' }}</span>
                    </div>
                    <div class="flex">
                        <span class="w-32 text-xs text-slate-500 shrink-0">Phone</span>
                        <span class="text-sm text-slate-700">{{ $business->owner?->phone ?? '—' }}</span>
                    </div>
                    <div class="flex">
                        <span class="w-32 text-xs text-slate-500 shrink-0">Status</span>
                        <span class="text-sm text-slate-700">{{ $business->owner?->status ?? '—' }}</span>
                    </div>
                    <div class="flex">
                        <span class="w-32 text-xs text-slate-500 shrink-0">Email Verified</span>
                        @if($business->owner?->email_verified_at)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Verified</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Unverified</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Team members --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Team ({{ $business->users->count() }})</h3>
        </div>
        <div class="px-5 py-4">
            @if($business->users->isEmpty())
                <p class="text-sm text-slate-400">No team members.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-100">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Role</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($business->users as $member)
                                <tr>
                                    <td class="px-2 py-2 text-slate-900">{{ $member->name }}</td>
                                    <td class="px-2 py-2 text-xs text-slate-500">{{ $member->email }}</td>
                                    <td class="px-2 py-2">
                                        @foreach($member->roles as $role)
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 mr-1">{{ $role->name }}</span>
                                        @endforeach
                                    </td>
                                    <td class="px-2 py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $member->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ $member->status }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Stores --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Stores ({{ $business->stores->count() }})</h3>
            <a href="{{ route('admin.stores.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Add Store</a>
        </div>
        <div class="px-5 py-4">
            @if($business->stores->isEmpty())
                <p class="text-sm text-slate-400">No stores yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-100">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Store</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Ownership</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($business->stores as $s)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-2 py-2">
                                        <a href="{{ route('admin.stores.show', $s) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $s->name }}</a>
                                        <div class="text-xs text-slate-400 font-mono">{{ $s->store_id }}</div>
                                    </td>
                                    <td class="px-2 py-2 text-slate-700">{{ $s->ownershipType?->name ?? '—' }}</td>
                                    <td class="px-2 py-2 text-slate-700">{{ $s->businessType?->name ?? '—' }}</td>
                                    <td class="px-2 py-2">
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ $s->status }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Warehouses --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Warehouses ({{ $business->warehouses->count() }})</h3>
        </div>
        <div class="px-5 py-4">
            @if($business->warehouses->isEmpty())
                <p class="text-sm text-slate-400">No warehouses yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-100">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Stock Items</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($business->warehouses as $wh)
                                <tr>
                                    <td class="px-2 py-2 text-slate-900">{{ $wh->name }}</td>
                                    <td class="px-2 py-2"><code class="text-xs text-slate-600">{{ $wh->warehouse_code }}</code></td>
                                    <td class="px-2 py-2 text-slate-700">{{ $wh->stock_locations_count }}</td>
                                    <td class="px-2 py-2">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $wh->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ $wh->status }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- KYC --}}
@php($kyc = $business->kycApplications->first())
<div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">KYC Verification</h3>
    </div>
    <div class="px-5 py-4">
        @if($kyc)
            <div class="space-y-3 mb-4">
                <div class="flex">
                    <span class="w-32 text-xs text-slate-500 shrink-0">Status</span>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                        {{ $kyc->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : ($kyc->status === 'rejected' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                        {{ $kyc->status }}
                    </span>
                </div>
                <div class="flex">
                    <span class="w-32 text-xs text-slate-500 shrink-0">Submitted</span>
                    <span class="text-sm text-slate-700">{{ $kyc->created_at->format('d M Y, H:i') }}</span>
                </div>
                @if($kyc->reviewed_by)
                <div class="flex">
                    <span class="w-32 text-xs text-slate-500 shrink-0">Reviewed by</span>
                    <span class="text-sm text-slate-700">Admin #{{ $kyc->reviewed_by }} on {{ optional($kyc->reviewed_at)->format('d M Y') }}</span>
                </div>
                @endif
            </div>
            <a href="{{ route('admin.vendor-kyc.show', $kyc) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">View KYC Details</a>
        @else
            <p class="text-sm text-slate-400">No KYC application submitted yet.</p>
        @endif
    </div>
</div>

@else
{{-- Fallback: old vendor view (no Business record) --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Account Details</h3>
        </div>
        <div class="px-5 py-4 space-y-3">
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Name</span>
                <span class="text-sm text-slate-900">{{ $user->name }}</span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Email</span>
                <span class="text-sm text-slate-700">{{ $user->email ?? '—' }}</span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Phone</span>
                <span class="text-sm text-slate-700">{{ $user->phone ?? '—' }}</span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Status</span>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ $user->status }}</span>
            </div>
            <div class="flex">
                <span class="w-32 text-xs text-slate-500 shrink-0">Registered</span>
                <span class="text-sm text-slate-700">{{ optional($user->created_at)->format('Y-m-d H:i') }}</span>
            </div>
        </div>
        <div class="px-5 py-3 bg-amber-50 border-t border-amber-100">
            <p class="text-sm text-amber-700">This vendor has no Business record yet. The old vendor flow is deprecated.</p>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Stores</h3>
        </div>
        <div class="px-5 py-4">
            @forelse($user->stores as $s)
                <a href="{{ route('admin.stores.show', $s) }}" class="text-sm text-indigo-600 hover:underline">{{ $s->name }}</a>{{ !$loop->last ? ', ' : '' }}
            @empty
                <p class="text-sm text-slate-400">No stores.</p>
            @endforelse
        </div>
    </div>
</div>
@endif

{{-- Modals (rewritten in Tailwind, operate on owner User) --}}
@include('admin.vendors._modals')
@endsection

@push('scripts')
<script>
function deleteVendor(accountId, name) {
    var nameInput = document.getElementById('deleteVendorName');
    var form = document.getElementById('deleteVendorForm');
    if (nameInput) nameInput.value = name || '';
    if (form && accountId) {
        form.action = "{{ url('superadmin/vendors') }}/" + accountId;
    }
    openModal('deleteVendorModal');
}

function editVendorModal(accountId, name, slug, email, phone, status) {
    document.getElementById('editVendorName').value = name || '';
    document.getElementById('editVendorSlug').value = slug || '';
    document.getElementById('editVendorEmail').value = email || '';
    document.getElementById('editVendorPhone').value = phone || '';
    document.getElementById('editVendorStatus').value = status || 'active';
    var form = document.getElementById('editVendorForm');
    if (form && accountId) {
        form.action = "{{ url('superadmin/vendors') }}/" + accountId + "?redirect=show";
    }
    openModal('editVendorModal');
}

function suspendVendor(accountId, name) {
    document.getElementById('suspendVendorName').value = name || '';
    var form = document.getElementById('suspendVendorForm');
    if (form && accountId) {
        form.action = "{{ url('superadmin/vendors') }}/" + accountId + "/suspend";
    }
    openModal('suspendVendorModal');
}
</script>
@endpush
