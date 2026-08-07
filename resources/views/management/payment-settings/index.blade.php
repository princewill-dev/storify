@extends('management.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Payment Settings" subtitle="Manage bank accounts and payment gateways for your business">
    <x-slot:actions>
        <a href="{{ route('management.dashboard') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-600 rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">
            ← Back
        </a>
    </x-slot:actions>
</x-management.page-header>

{{-- Bank Accounts Section --}}
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-6">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div>
            <h3 class="text-sm font-bold text-slate-800">Bank Accounts</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $bankAccounts->count() }} account(s) configured</p>
        </div>
        <button onclick="openBankModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors shadow-sm">
            <i class="fi fi-rr-plus text-xs"></i> Add Bank Account
        </button>
    </div>

    <div class="divide-y divide-slate-50">
        @forelse($bankAccounts as $bank)
        <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50/50 transition-colors">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <i class="fi fi-rr-bank text-blue-500 text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-semibold text-slate-800">{{ $bank->bank_name }}</p>
                        @if($bank->is_primary)
                        <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                            <i class="fi fi-rr-star text-[8px]"></i> Primary
                        </span>
                        @endif
                        @if($bank->is_verified)
                        <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">
                            <i class="fi fi-rr-check text-[8px]"></i> Verified
                        </span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $bank->account_name }} · {{ $bank->masked_account_number }}</p>
                </div>
            </div>
            <div class="flex items-center gap-1">
                @if(!$bank->is_primary)
                <form method="POST" action="{{ route('management.payment-settings.bank-accounts.update', $bank) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="bank_code" value="{{ $bank->bank_code }}">
                    <input type="hidden" name="bank_name" value="{{ $bank->bank_name }}">
                    <input type="hidden" name="account_number" value="{{ $bank->account_number }}">
                    <input type="hidden" name="account_name" value="{{ $bank->account_name }}">
                    <input type="hidden" name="is_primary" value="1">
                    <button class="px-3 py-1.5 text-xs font-medium text-amber-600 bg-amber-50 rounded-lg hover:bg-amber-100 transition-colors">Set Primary</button>
                </form>
                @endif
                <button onclick="editBank('{{ $bank->id }}', '{{ $bank->bank_name }}', '{{ $bank->account_name }}', '{{ $bank->account_number }}', '{{ $bank->bank_code }}')" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors">Edit</button>
                <form method="POST" action="{{ route('management.payment-settings.bank-accounts.destroy', $bank) }}" onsubmit="return confirm('Remove this bank account?')">
                    @csrf @method('DELETE')
                    <button class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">Remove</button>
                </form>
            </div>
        </div>
        @empty
        <div class="px-6 py-12 text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                <i class="fi fi-rr-bank text-2xl text-slate-300"></i>
            </div>
            <h4 class="text-sm font-semibold text-slate-700 mb-1">No bank accounts yet</h4>
            <p class="text-sm text-slate-400 mb-4 max-w-sm mx-auto">Add a bank account so customers can pay via bank transfer.</p>
            <button onclick="openBankModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors">
                <i class="fi fi-rr-plus text-xs"></i> Add Bank Account
            </button>
        </div>
        @endforelse
    </div>
</div>

{{-- Payment Gateways Section --}}
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div>
            <h3 class="text-sm font-bold text-slate-800">Payment Gateways</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ $gateways->count() }} gateway(s) connected</p>
        </div>
        <button onclick="openGatewayModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors shadow-sm">
            <i class="fi fi-rr-plus text-xs"></i> Connect Gateway
        </button>
    </div>

    <div class="divide-y divide-slate-50">
        @forelse($gateways as $gw)
        <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50/50 transition-colors">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center shrink-0">
                    <i class="fi fi-rr-credit-card text-indigo-500 text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-semibold text-slate-800">{{ $gw->name ?? 'Paystack' }}</p>
                        <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full {{ $gw->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-slate-100 text-slate-500 border border-slate-200' }}">
                            <span class="w-1 h-1 rounded-full {{ $gw->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                            {{ $gw->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3 mt-0.5">
                        <p class="text-xs text-slate-400">Key: {{ \Illuminate\Support\Str::limit($gw->config['public_key'] ?? '—', 18) }}</p>
                        <span class="text-[10px] text-slate-300">·</span>
                        <span class="text-xs text-slate-400">{{ $gw->assigned_count }} store(s) assigned</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-1">
                <form method="POST" action="{{ route('management.payment-settings.gateways.test', $gw->id) }}">
                    @csrf
                    <button class="px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">Test</button>
                </form>
                <button onclick="editGateway('{{ $gw->id }}', '{{ $gw->config['public_key'] ?? '' }}', '{{ $gw->config['secret_key'] ?? '' }}')" class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors">Edit</button>
                <form method="POST" action="{{ route('management.payment-settings.gateways.toggle', $gw->id) }}">
                    @csrf @method('PATCH')
                    <button class="px-3 py-1.5 text-xs font-medium text-amber-600 bg-amber-50 rounded-lg hover:bg-amber-100 transition-colors">{{ $gw->is_active ? 'Disable' : 'Enable' }}</button>
                </form>
                <form method="POST" action="{{ route('management.payment-settings.gateways.destroy', $gw->id) }}" onsubmit="return confirm('Remove this gateway?')">
                    @csrf @method('DELETE')
                    <button class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">Remove</button>
                </form>
            </div>
        </div>
        @empty
        <div class="px-6 py-12 text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 mb-4">
                <i class="fi fi-rr-credit-card text-2xl text-slate-300"></i>
            </div>
            <h4 class="text-sm font-semibold text-slate-700 mb-1">No gateways connected</h4>
            <p class="text-sm text-slate-400 mb-4 max-w-sm mx-auto">Connect a payment gateway like Paystack to accept card payments online.</p>
            <button onclick="openGatewayModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors">
                <i class="fi fi-rr-plus text-xs"></i> Connect Gateway
            </button>
        </div>
        @endforelse
    </div>
</div>

{{-- Add Bank Account Modal --}}
<div id="bankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeBankModal()"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-900">Add Bank Account</h3>
                <button onclick="closeBankModal()" class="text-slate-400 hover:text-slate-600"><i class="fi fi-rr-cross-small text-lg"></i></button>
            </div>

            <form id="bankForm" method="POST" action="{{ route('management.payment-settings.bank-accounts.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Bank <span class="text-red-500">*</span></label>
                    <select name="bank_code" id="bankSelect" required onchange="onBankSelect(this)" class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                        <option value="">Select a bank...</option>
                        @foreach($banks as $b)
                        <option value="{{ $b['code'] }}" data-name="{{ $b['name'] }}">{{ $b['name'] }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="bank_name" id="bankNameHidden">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Account Number <span class="text-red-500">*</span></label>
                    <input type="text" name="account_number" id="bankAccountNumber" maxlength="10" required
                        class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10"
                        placeholder="10-digit account number"
                        oninput="if(this.value.length>=10)verifyBank()">
                </div>

                <div id="verifyStatus" class="hidden text-xs"></div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Account Name</label>
                    <input type="text" name="account_name" id="bankAccountName" required readonly
                        class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm bg-slate-50 focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10"
                        placeholder="Auto-filled after verification">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Assign to Store (optional)</label>
                    <select name="store_id" class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                        <option value="">All stores (default)</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeBankModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" id="bankSubmit" disabled class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors disabled:opacity-50">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Bank Account Modal --}}
<div id="editBankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeEditBankModal()"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-900">Edit Bank Account</h3>
                <button onclick="closeEditBankModal()" class="text-slate-400 hover:text-slate-600"><i class="fi fi-rr-cross-small text-lg"></i></button>
            </div>

            <form id="editBankForm" method="POST" action="" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="bank_code" id="editBankCode">
                <input type="hidden" name="bank_name" id="editBankName">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Bank</label>
                    <input type="text" id="editBankNameDisplay" readonly class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm bg-slate-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Account Number</label>
                    <input type="text" name="account_number" id="editBankAccountNumber" readonly class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm bg-slate-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Account Name</label>
                    <input type="text" name="account_name" id="editBankAccountName" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeEditBankModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Connect Gateway Modal --}}
<div id="gatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeGatewayModal()"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-900">Connect Payment Gateway</h3>
                <button onclick="closeGatewayModal()" class="text-slate-400 hover:text-slate-600"><i class="fi fi-rr-cross-small text-lg"></i></button>
            </div>

            <form method="POST" action="{{ route('management.payment-settings.gateways.store') }}" class="space-y-4">
                @csrf
                <div class="p-3 bg-indigo-50 rounded-xl flex items-center gap-3 mb-2">
                    <i class="fi fi-rr-credit-card text-indigo-500 text-xl"></i>
                    <div>
                        <p class="text-sm font-semibold text-indigo-800">Paystack</p>
                        <p class="text-xs text-indigo-500">Connect your Paystack account</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Public Key <span class="text-red-500">*</span></label>
                    <input type="text" name="public_key" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="pk_test_...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Secret Key <span class="text-red-500">*</span></label>
                    <input type="text" name="secret_key" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10" placeholder="sk_test_...">
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeGatewayModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors">Connect</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Gateway Modal --}}
<div id="editGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeEditGatewayModal()"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-slate-900">Edit Gateway</h3>
                <button onclick="closeEditGatewayModal()" class="text-slate-400 hover:text-slate-600"><i class="fi fi-rr-cross-small text-lg"></i></button>
            </div>

            <form id="editGatewayForm" method="POST" action="" class="space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Public Key <span class="text-red-500">*</span></label>
                    <input type="text" name="public_key" id="editGatewayPublicKey" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Secret Key <span class="text-red-500">*</span></label>
                    <input type="text" name="secret_key" id="editGatewaySecretKey" required class="block w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-500/10">
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeEditGatewayModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openBankModal() { document.getElementById('bankModal').classList.remove('hidden'); }
function closeBankModal() { document.getElementById('bankModal').classList.add('hidden'); document.getElementById('bankForm').reset(); document.getElementById('verifyStatus').classList.add('hidden'); document.getElementById('bankSubmit').disabled = true; }
function openGatewayModal() { document.getElementById('gatewayModal').classList.remove('hidden'); }
function closeGatewayModal() { document.getElementById('gatewayModal').classList.add('hidden'); }
function closeEditBankModal() { document.getElementById('editBankModal').classList.add('hidden'); }
function closeEditGatewayModal() { document.getElementById('editGatewayModal').classList.add('hidden'); }

function onBankSelect(el) {
    const name = el.options[el.selectedIndex].dataset.name || '';
    document.getElementById('bankNameHidden').value = name;
}

function editBank(id, name, accountName, accountNumber, bankCode) {
    const form = document.getElementById('editBankForm');
    form.action = '/management/payment-settings/bank-accounts/' + id;
    document.getElementById('editBankCode').value = bankCode;
    document.getElementById('editBankName').value = name;
    document.getElementById('editBankNameDisplay').value = name;
    document.getElementById('editBankAccountNumber').value = accountNumber;
    document.getElementById('editBankAccountName').value = accountName;
    document.getElementById('editBankModal').classList.remove('hidden');
}

function editGateway(id, publicKey, secretKey) {
    const form = document.getElementById('editGatewayForm');
    form.action = '/management/payment-settings/gateways/' + id;
    document.getElementById('editGatewayPublicKey').value = publicKey;
    document.getElementById('editGatewaySecretKey').value = secretKey;
    document.getElementById('editGatewayModal').classList.remove('hidden');
}

async function verifyBank() {
    const accountNumber = document.getElementById('bankAccountNumber').value;
    const bankCode = document.getElementById('bankSelect').value;
    const status = document.getElementById('verifyStatus');
    const submit = document.getElementById('bankSubmit');
    const accountName = document.getElementById('bankAccountName');

    if (!accountNumber || accountNumber.length < 10 || !bankCode) return;

    status.classList.remove('hidden', 'text-red-500', 'text-emerald-500');
    status.classList.add('text-slate-400');
    status.textContent = 'Verifying...';

    try {
        const resp = await fetch('/management/payment-settings/verify-bank', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ account_number: accountNumber, bank_code: bankCode })
        });
        const data = await resp.json();

        if (data.success) {
            status.classList.remove('text-slate-400', 'text-red-500');
            status.classList.add('text-emerald-500');
            status.textContent = '✓ Account verified';
            accountName.value = data.account_name;
            accountName.readOnly = false;
            accountName.classList.remove('bg-slate-50');
            submit.disabled = false;
        } else {
            status.classList.remove('text-slate-400', 'text-emerald-500');
            status.classList.add('text-red-500');
            status.textContent = '✗ ' + (data.message || 'Verification failed');
            submit.disabled = true;
        }
    } catch(e) {
        status.classList.remove('text-slate-400', 'text-emerald-500');
        status.classList.add('text-red-500');
        status.textContent = '✗ Verification failed. Try again.';
        submit.disabled = true;
    }
}
</script>
@endpush
