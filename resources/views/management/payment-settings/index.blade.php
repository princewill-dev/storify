@extends('management.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Payment Settings" subtitle="Per-store payment gateways and bank accounts" />

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="space-y-6">
    @forelse($stores as $store)
        @php
            $storeBanks = $store->banks;
            $paystack = $store->paymentGateways->where('gateway', 'paystack')->first();
            $hasPaystack = $paystack && $paystack->is_active;
            $hasBank = $storeBanks->isNotEmpty();
        @endphp
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        {{-- Store Header --}}
        <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-slate-100 text-slate-500 shrink-0">
                    <i class="fi fi-rr-shop text-sm"></i>
                </span>
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">{{ $store->name }}</h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $storeBanks->count() }} bank{{ $storeBanks->count() !== 1 ? 's' : '' }} · Paystack {{ $hasPaystack ? 'connected' : 'not connected' }}
                    </p>
                </div>
            </div>
            <form method="POST" action="{{ route('management.payment-settings.toggle-mode', $store) }}" class="flex items-center gap-2 shrink-0">
                @csrf
                <input type="hidden" name="payment_mode" value="{{ $store->payment_mode === 'auto' ? 'manual' : 'auto' }}">
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $store->payment_mode === 'auto' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }} ring-1 ring-inset {{ $store->payment_mode === 'auto' ? 'ring-blue-600/20' : 'ring-amber-600/20' }}">
                    {{ $store->payment_mode === 'auto' ? 'Auto' : 'Manual' }}
                </span>
                <button type="submit" class="text-[10px] text-slate-400 hover:text-slate-600 underline">Switch to {{ $store->payment_mode === 'auto' ? 'Manual' : 'Auto' }}</button>
            </form>
        </div>

        <div class="divide-y divide-slate-100">
            {{-- Bank Accounts Row --}}
            <div class="px-5 py-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Bank Accounts</h4>
                    <button onclick="openBankModal('{{ $store->id }}')" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        <i class="fi fi-rr-plus text-[10px]"></i> Add
                    </button>
                </div>
                @if($storeBanks->isEmpty())
                <p class="text-xs text-slate-400 py-2">No bank accounts yet. Add one to enable manual bank transfers.</p>
                @else
                <div class="space-y-2">
                    @foreach($storeBanks as $bank)
                    <div class="flex items-center justify-between text-sm py-1.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-medium text-slate-700">{{ $bank->bank_name }}</span>
                            <span class="text-slate-400 ml-2">{{ $bank->account_number }}</span>
                            @if($bank->is_primary)<span class="inline-flex items-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 ml-2">Primary</span>@endif
                        </div>
                        <div class="flex items-center gap-1 shrink-0 ml-3">
                            <button onclick="openEditBankModal(@js($bank->only(['id','bank_name','account_number','account_name','store_id','is_primary'])))" class="p-1 text-slate-400 hover:text-blue-600" title="Edit"><i class="fi fi-rr-edit text-xs"></i></button>
                            <button onclick="openDeleteBankModal(@js($bank->only(['id','bank_name'])))" class="p-1 text-slate-400 hover:text-red-600" title="Delete"><i class="fi fi-rr-trash text-xs"></i></button>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Paystack Gateway Row --}}
            <div class="px-5 py-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Paystack Gateway</h4>
                    @if(!$paystack)
                    <button onclick="openGatewayModal('{{ $store->id }}')" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        <i class="fi fi-rr-plus text-[10px]"></i> Connect
                    </button>
                    @endif
                </div>
                @if(!$paystack)
                <p class="text-xs text-slate-400 py-2">No Paystack keys configured. Connect to accept card payments via POS.</p>
                @else
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-sm py-1.5">
                    <div class="min-w-0">
                        <span class="font-mono text-slate-600 text-xs">{{ $paystack->masked_public_key }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium ml-2 {{ $paystack->is_active ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500' }}">
                            {{ $paystack->is_active ? 'Active' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button onclick="openEditGatewayModal(@js($paystack->only(['id','store_id','masked_public_key'])))" class="px-2 py-1 text-[11px] font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg">Edit</button>
                        <button onclick="testGateway({{ $paystack->id }})" id="testBtn{{ $paystack->id }}" class="px-2 py-1 text-[11px] font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg">Test</button>
                        <form method="POST" action="{{ route('management.payment-settings.gateways.toggle', $paystack) }}" class="inline">@csrf @method('PATCH')<button class="px-2 py-1 text-[11px] font-medium text-amber-600 bg-amber-50 hover:bg-amber-100 rounded-lg">{{ $paystack->is_active ? 'Disable' : 'Enable' }}</button></form>
                        <button onclick="openDeleteGatewayModal(@js($paystack->only(['id','store_id'])))" class="p-1 text-slate-400 hover:text-red-600"><i class="fi fi-rr-trash text-xs"></i></button>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center">
        <span class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 mb-4"><i class="fi fi-rr-shop text-2xl"></i></span>
        <h3 class="text-sm font-semibold text-slate-700 mb-1">No stores yet</h3>
        <p class="text-xs text-slate-400">Create a store first to configure payment settings.</p>
    </div>
    @endforelse
</div>

{{-- Add Bank Modal --}}
<div id="addBankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('addBankModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Add Bank Account</h3>
                <button onclick="document.getElementById('addBankModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form method="POST" action="{{ route('management.payment-settings.bank-accounts.store') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="store_id" id="addBankStoreId">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Bank <span class="text-red-500">*</span></label>
                    <select name="bank_code" id="addBankCode" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required onchange="onBankSelect(this)">
                        <option value="">Select bank</option>
                        @foreach($banks as $bank)
                        <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="bank_name" id="addBankName">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Number <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <input type="text" name="account_number" id="addAccountNumber" maxlength="10" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="10-digit account number" required>
                        <button type="button" id="verifyBankBtn" class="px-4 py-2 bg-slate-100 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50">Verify</button>
                    </div>
                    <p id="verifyFeedback" class="text-xs"></p>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Name</label>
                    <input type="text" name="account_name" id="addAccountName" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm bg-slate-50" placeholder="Auto-filled after verification" readonly>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" id="addBankSubmit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors disabled:opacity-50" disabled>Save Account</button>
                    <button type="button" onclick="document.getElementById('addBankModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Bank Modal --}}
<div id="editBankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('editBankModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit Bank Account</h3>
                <button onclick="document.getElementById('editBankModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form id="editBankForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="store_id" id="editBankStoreId">
                <input type="hidden" name="bank_code" id="editBankCode">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Bank Name</label>
                    <input type="text" name="bank_name" id="editBankName" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Number</label>
                    <input type="text" name="account_number" id="editBankAcct" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Name</label>
                    <input type="text" name="account_name" id="editBankAcctName" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" required>
                </div>
                <div class="flex items-center gap-2">
                    <input type="hidden" name="is_primary" value="0">
                    <input type="checkbox" name="is_primary" id="editBankPrimary" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <label for="editBankPrimary" class="text-sm text-slate-700">Set as primary</label>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update</button>
                    <button type="button" onclick="document.getElementById('editBankModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Bank Modal --}}
<div id="deleteBankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('deleteBankModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Delete Bank Account?</h3>
            <p class="text-sm text-slate-500 mb-4">Remove <strong id="deleteBankName"></strong>?</p>
            <form id="deleteBankForm" method="POST">
                @csrf @method('DELETE')
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Delete</button>
                    <button type="button" onclick="document.getElementById('deleteBankModal').classList.add('hidden')" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Gateway Modal --}}
<div id="addGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('addGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Connect Paystack</h3>
                <button onclick="document.getElementById('addGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form method="POST" action="{{ route('management.payment-settings.gateways.store') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="store_id" id="addGatewayStoreId">
                <p class="text-sm text-slate-500">Enter your Paystack API keys to accept card payments via POS for this store.</p>
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
                    <button type="button" onclick="document.getElementById('addGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Gateway Modal --}}
<div id="editGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('editGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Edit Paystack Keys</h3>
                <button onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form id="editGatewayForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="store_id" id="editGatewayStoreId">
                <p class="text-sm text-slate-500">Current key: <span id="editGatewayCurrKey" class="font-mono text-slate-600"></span></p>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Public Key</label>
                    <input type="text" name="public_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="pk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secret Key</label>
                    <input type="password" name="secret_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="sk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update Keys</button>
                    <button type="button" onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Gateway Modal --}}
<div id="deleteGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('deleteGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Remove Paystack Keys?</h3>
            <p class="text-sm text-slate-500 mb-4">Card payments will no longer work for this store.</p>
            <form id="deleteGatewayForm" method="POST">
                @csrf @method('DELETE')
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Remove</button>
                    <button type="button" onclick="document.getElementById('deleteGatewayModal').classList.add('hidden')" class="px-4 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openBankModal(storeId) {
    document.getElementById('addBankStoreId').value = storeId;
    document.getElementById('addBankCode').value = '';
    document.getElementById('addBankName').value = '';
    document.getElementById('addAccountNumber').value = '';
    document.getElementById('addAccountName').value = '';
    document.getElementById('addBankSubmit').disabled = true;
    document.getElementById('verifyFeedback').textContent = '';
    document.getElementById('addBankModal').classList.remove('hidden');
}

function onBankSelect(sel) { document.getElementById('addBankName').value = sel.options[sel.selectedIndex].dataset.name || ''; resetVerification(); }

function resetVerification() {
    document.getElementById('addAccountName').value = '';
    document.getElementById('addBankSubmit').disabled = true;
    document.getElementById('verifyFeedback').textContent = '';
}

function openEditBankModal(bank) {
    document.getElementById('editBankForm').action = '/management/payment-settings/bank-accounts/' + bank.id;
    document.getElementById('editBankStoreId').value = bank.store_id || '';
    document.getElementById('editBankName').value = bank.bank_name || '';
    document.getElementById('editBankAcct').value = bank.account_number || '';
    document.getElementById('editBankAcctName').value = bank.account_name || '';
    document.getElementById('editBankPrimary').checked = bank.is_primary || false;
    document.getElementById('editBankModal').classList.remove('hidden');
}

function openDeleteBankModal(bank) {
    document.getElementById('deleteBankForm').action = '/management/payment-settings/bank-accounts/' + bank.id;
    document.getElementById('deleteBankName').textContent = bank.bank_name || 'this account';
    document.getElementById('deleteBankModal').classList.remove('hidden');
}

function openGatewayModal(storeId) {
    document.getElementById('addGatewayStoreId').value = storeId;
    document.getElementById('addGatewayModal').classList.remove('hidden');
}

function openEditGatewayModal(gw) {
    document.getElementById('editGatewayForm').action = '/management/payment-settings/gateways/' + gw.id;
    document.getElementById('editGatewayStoreId').value = gw.store_id || '';
    document.getElementById('editGatewayCurrKey').textContent = gw.masked_public_key || '';
    document.getElementById('editGatewayModal').classList.remove('hidden');
}

function openDeleteGatewayModal(gw) {
    document.getElementById('deleteGatewayForm').action = '/management/payment-settings/gateways/' + gw.id;
    document.getElementById('deleteGatewayModal').classList.remove('hidden');
}

function testGateway(id) {
    const btn = document.getElementById('testBtn' + id);
    const orig = btn.textContent;
    btn.textContent = 'Testing...';
    btn.disabled = true;
    fetch('/management/payment-settings/gateways/' + id + '/test', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    }).then(r => r.json()).then(d => {
        alert(d.success ? 'Connected! Keys are valid.' : 'Failed: ' + (d.message || 'Unknown error'));
    }).catch(() => alert('Test failed. Check your connection.')).finally(() => {
        btn.textContent = orig; btn.disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const bankSelect = document.getElementById('addBankCode');
    const accountInput = document.getElementById('addAccountNumber');
    const accountName = document.getElementById('addAccountName');
    const verifyBtn = document.getElementById('verifyBankBtn');
    const verifyFeedback = document.getElementById('verifyFeedback');
    const submitBtn = document.getElementById('addBankSubmit');

    function doVerify() {
        const bankCode = bankSelect?.value;
        const accountNumber = accountInput?.value;
        if (!bankCode || accountNumber?.length !== 10) {
            verifyFeedback.textContent = 'Select a bank and enter 10-digit account number.';
            verifyFeedback.className = 'text-xs text-red-500'; return;
        }
        verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying...';
        fetch('{{ route("management.payment-settings.verify-bank") }}', {
            method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
            body: JSON.stringify({bank_code: bankCode, account_number: accountNumber})
        }).then(r => r.json()).then(d => {
            if (d.success && d.account_name) {
                accountName.value = d.account_name; submitBtn.disabled = false;
                verifyFeedback.textContent = 'Verified!'; verifyFeedback.className = 'text-xs text-emerald-600';
            } else {
                verifyFeedback.textContent = d.message || 'Could not verify.'; verifyFeedback.className = 'text-xs text-red-500';
            }
        }).catch(() => { verifyFeedback.textContent = 'Verification failed.'; verifyFeedback.className = 'text-xs text-red-500'; })
        .finally(() => { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify'; });
    }

    if (accountInput) {
        accountInput.addEventListener('input', function() { resetVerification(); if (this.value.length === 10 && bankSelect?.value) doVerify(); });
    }
    if (bankSelect) bankSelect.addEventListener('change', function() { resetVerification(); if (accountInput?.value.length === 10 && this.value) doVerify(); });
    if (verifyBtn) verifyBtn.addEventListener('click', doVerify);
});
</script>
@endpush
