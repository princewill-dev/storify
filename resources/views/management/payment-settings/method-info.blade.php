@extends('management.layout')
@section('subtitle', $name)

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$name" subtitle="{{ $typeLabel }}">
    <x-slot:actions>
        @if($gateway)
        <button onclick="testGateway({{ $gateway->id }})" id="testGwBtn" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 hover:bg-blue-100 rounded-lg transition-colors">
            <i class="fi fi-rr-refresh text-xs"></i> Test Connection
        </button>
        @endif
    </x-slot:actions>
</x-management.page-header>

<div class="flex items-center gap-3 mb-4">
    <h3 class="text-sm font-semibold text-slate-800">Assigned Stores</h3>
    <span class="text-xs text-slate-400">{{ $assignedStores->count() }} store(s)</span>
    @if($availableStores->isNotEmpty())
    <button onclick="document.getElementById('assignModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-white bg-slate-900 rounded-lg hover:bg-slate-800">
        <i class="fi fi-rr-plus text-[10px]"></i> Assign to Store
    </button>
    @endif
</div>

@if($assignedStores->isEmpty())
<div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
    <p class="text-sm text-slate-400">Not assigned to any store yet.</p>
</div>
@else
<div class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Store</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach($assignedStores as $store)
            <tr class="hover:bg-slate-50/50 transition-colors">
                <td class="px-5 py-3">
                    <a href="{{ route('management.stores.show', $store) }}" class="flex items-center gap-3 group">
                        <span class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400 text-xs font-bold">{{ strtoupper(substr($store->name, 0, 2)) }}</span>
                        <span class="text-sm font-medium text-slate-800 group-hover:text-blue-600">{{ $store->name }}</span>
                    </a>
                </td>
                <td class="px-5 py-3 hidden sm:table-cell"><x-management.status-badge :status="$store->status" /></td>
                <td class="px-5 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('management.stores.show', $store) }}#settings" class="px-2.5 py-1 text-[11px] font-medium text-slate-600 hover:bg-slate-100 rounded-lg">Manage</a>
                        <button onclick="openModal('unassignStore{{ $store->id }}')" class="px-2 py-1 text-[11px] font-medium text-red-500 hover:bg-red-50 rounded-lg">Remove</button>
                        <x-management.confirm-modal id="unassignStore{{ $store->id }}" title="Unassign from Store" message="Remove from {{ $store->name }}?" action="{{ route('management.payment-settings.unassign-store', ['id' => request()->route('id'), 'type' => $gateway ? 'gateway' : 'bank', 'store_id' => $store->id]) }}" method="DELETE" />
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Assign Modal --}}
<div id="assignModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('assignModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-1">Assign to Store</h3>
            <p class="text-sm text-slate-500 mb-4">Select a store to assign this payment method to.</p>
            <form method="POST" action="{{ route('management.payment-settings.assign-store', ['id' => request()->route('id'), 'type' => $gateway ? 'gateway' : 'bank']) }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Store</label>
                    <select name="store_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                        <option value="">Select a store</option>
                        @foreach($availableStores as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->store_id }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Assign</button>
                    <button type="button" onclick="document.getElementById('assignModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function testGateway(id) {
    const btn = document.getElementById('testGwBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fi fi-rr-refresh text-xs"></i> Testing...';
    btn.disabled = true;
    fetch('/management/payment-settings/gateways/' + id + '/test', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    }).then(r => r.json()).then(d => {
        alert(d.success ? 'Connected! Keys valid.' : 'Failed: ' + (d.message || 'Error'));
    }).catch(() => alert('Test failed')).finally(() => {
        btn.innerHTML = orig; btn.disabled = false;
    });
}
</script>
@endpush

<div class="mt-6">
    <a href="{{ route('management.payment-settings.index') }}" class="text-sm text-slate-500 hover:text-slate-700">
        <i class="fi fi-rr-arrow-left text-xs mr-1"></i> Back to Payment Settings
    </a>
</div>
@endsection
