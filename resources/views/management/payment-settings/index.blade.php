@extends('management.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Payment Settings" subtitle="Manage your business-wide payment gateway and default payment configuration" />

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Paystack Gateway --}}
        <x-management.card header="Paystack Integration">
            @php
                $bizGateway = \App\Models\BusinessGateway::where('business_id', $user->business_id)
                    ->where('gateway', 'paystack')->first();
            @endphp
            @if($bizGateway)
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600">
                            <i class="fi fi-rr-credit-card"></i>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Paystack Connected</p>
                            <p class="text-xs text-slate-400">Public Key: <code class="text-slate-600">{{ $bizGateway->masked_public_key }}</code></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $bizGateway->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20' : 'bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-600/10' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $bizGateway->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                            {{ $bizGateway->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2 pt-2 border-t border-slate-100">
                    <button onclick="openEditBusinessGateway(@js($bizGateway->only(['id','public_key','secret_key','is_active'])))" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50">
                        <i class="fi fi-rr-edit text-[10px] mr-1"></i> Edit Keys
                    </button>
                    <button onclick="testBusinessGateway({{ $bizGateway->id }})" id="testBizGwBtn" class="px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100">
                        <i class="fi fi-rr-refresh text-[10px] mr-1"></i> Test Connection
                    </button>
                    <form method="POST" action="{{ route('management.payment-settings.gateways.toggle', $bizGateway) }}" class="inline">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1.5 text-xs font-medium {{ $bizGateway->is_active ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' }} rounded-lg">
                            {{ $bizGateway->is_active ? 'Disable' : 'Enable' }}
                        </button>
                    </form>
                    <button onclick="openDeleteBusinessGateway(@js($bizGateway->only(['id'])))" class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100">
                        <i class="fi fi-rr-trash text-[10px] mr-1"></i> Remove
                    </button>
                </div>
            </div>
            @else
            <div class="text-center py-6">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3">
                    <i class="fi fi-rr-credit-card text-xl"></i>
                </span>
                <h3 class="text-sm font-semibold text-slate-700 mb-1">Not Connected</h3>
                <p class="text-xs text-slate-400 mb-4 max-w-sm mx-auto">Connect your Paystack account to accept card payments across all your stores and POS terminals.</p>
                <button onclick="openAddBusinessGateway()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    <i class="fi fi-rr-plus text-xs"></i> Connect Paystack
                </button>
            </div>
            @endif
        </x-management.card>

    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">
        <x-management.card header="About Payment Gateway">
            <div class="text-sm text-slate-500 space-y-3">
                <p>The <strong>business-wide Paystack gateway</strong> is used as the default for all stores and POS terminals in your business.</p>
                <p>Individual stores can override with their own Paystack keys from their Settings tab if needed.</p>
                <p>Bank accounts for transfer payments are managed <strong>per store</strong> — visit a store's Settings tab to add bank details.</p>
            </div>
        </x-management.card>
    </div>
</div>

{{-- Add Business Gateway Modal --}}
<div id="addBizGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('addBizGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Connect Paystack</h3>
                <button onclick="document.getElementById('addBizGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form method="POST" action="{{ route('management.payment-settings.gateways.store') }}" class="p-6 space-y-4">
                @csrf
                <p class="text-sm text-slate-500">Enter your Paystack API keys. These will be the default payment gateway for all stores in your business.</p>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Public Key</label>
                    <input type="text" name="public_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="pk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secret Key</label>
                    <input type="password" name="secret_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="sk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Connect</button>
                    <button type="button" onclick="document.getElementById('addBizGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Business Gateway Modal --}}
<div id="editBizGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('editBizGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit Paystack Keys</h3>
                <button onclick="document.getElementById('editBizGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form id="editBizGatewayForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Public Key</label>
                    <input type="text" name="public_key" id="editBizPublicKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="pk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secret Key</label>
                    <input type="password" name="secret_key" id="editBizSecretKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="sk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update Keys</button>
                    <button type="button" onclick="document.getElementById('editBizGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Business Gateway Modal --}}
<div id="deleteBizGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('deleteBizGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Remove Paystack?</h3>
            <p class="text-sm text-slate-500 mb-4">Card payments will stop working across all stores until reconnected.</p>
            <form id="deleteBizGatewayForm" method="POST">
                @csrf @method('DELETE')
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Remove</button>
                    <button type="button" onclick="document.getElementById('deleteBizGatewayModal').classList.add('hidden')" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openAddBusinessGateway() { document.getElementById('addBizGatewayModal').classList.remove('hidden'); }

function openEditBusinessGateway(gw) {
    document.getElementById('editBizGatewayForm').action = '/management/payment-settings/gateways/' + gw.id;
    document.getElementById('editBizGatewayModal').classList.remove('hidden');
}

function openDeleteBusinessGateway(gw) {
    document.getElementById('deleteBizGatewayForm').action = '/management/payment-settings/gateways/' + gw.id;
    document.getElementById('deleteBizGatewayModal').classList.remove('hidden');
}

function testBusinessGateway(id) {
    const btn = document.getElementById('testBizGwBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fi fi-rr-refresh text-[10px] mr-1"></i> Testing...';
    btn.disabled = true;
    fetch('/management/payment-settings/gateways/' + id + '/test', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    }).then(r => r.json()).then(d => {
        alert(d.success ? 'Connected! Paystack keys are valid.' : 'Failed: ' + (d.message || 'Unknown error'));
    }).catch(() => alert('Test failed. Check your connection.')).finally(() => {
        btn.innerHTML = orig; btn.disabled = false;
    });
}
</script>
@endpush
