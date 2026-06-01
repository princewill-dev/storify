@extends('management.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<x-management.page-header title="Payment Settings" subtitle="Connect payment gateways and manage bank accounts" />

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="space-y-8">

    {{-- Payment Gateways --}}
    <div>
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Payment Gateways</h3>
                <p class="text-xs text-slate-400 mt-0.5">Connected gateways are available site-wide for POS, web store, and checkout</p>
            </div>
            <button onclick="document.getElementById('connectGatewayModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800 transition-colors">
                <i class="fi fi-rr-plus text-xs"></i> Connect Gateway
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Paystack Card --}}
            @php $paystack = $gateways->firstWhere('gateway', 'paystack'); @endphp
            @if($paystack)
            <div class="bg-white rounded-xl shadow-sm border-2 {{ $paystack->is_active && $paystack->is_verified ? 'border-emerald-300' : ($paystack->is_active ? 'border-amber-300' : 'border-slate-200') }} p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 shrink-0">
                            <i class="fi fi-rr-credit-card text-xs"></i>
                        </span>
                        <div>
                            <h4 class="text-sm font-semibold text-slate-800">Paystack</h4>
                            <p class="text-xs text-slate-400">Debit/credit cards, bank transfers</p>
                        </div>
                    </div>
                    @if($paystack->is_active && $paystack->is_verified)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-600 ring-1 ring-inset ring-emerald-600/20"><i class="fi fi-rr-check text-[10px]"></i> Connected</span>
                    @elseif($paystack->is_active)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-600 ring-1 ring-inset ring-amber-600/20">Not Verified</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-500/20">Disabled</span>
                    @endif
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-slate-400">Public Key</span>
                        <span class="font-mono text-slate-600">{{ $paystack->masked_public_key }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Secret Key</span>
                        <span class="font-mono text-slate-600">{{ $paystack->masked_secret_key }}</span>
                    </div>
                    @if($paystack->webhook_id)
                    <div class="flex justify-between">
                        <span class="text-slate-400">Webhook</span>
                        <span class="text-emerald-600"><i class="fi fi-rr-check text-[10px] mr-0.5"></i> Configured</span>
                    </div>
                    @endif
                </div>
                <div class="flex items-center gap-2 mt-4 pt-3 border-t border-slate-100">
                    <button onclick="openEditGateway(@js($paystack->only(['id','gateway','is_active','is_verified','masked_public_key','masked_secret_key','webhook_id'])))" class="flex-1 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors text-center">Edit Keys</button>
                    <button id="testKeysBtn" onclick="testGateway({{ $paystack->id }})" class="flex-1 py-1.5 text-xs font-medium {{ $paystack->is_verified ? 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' : 'text-blue-600 bg-blue-50 hover:bg-blue-100' }} rounded-lg transition-colors text-center">Test Keys</button>
                    @if(!$paystack->webhook_id)
                    <button onclick="configureWebhook({{ $paystack->id }})" class="flex-1 py-1.5 text-xs font-medium text-purple-600 bg-purple-50 hover:bg-purple-100 rounded-lg transition-colors text-center">Setup Webhook</button>
                    @endif
                </div>
            </div>
            @else
            <div class="bg-white rounded-xl shadow-sm border border-dashed border-slate-300 p-5 flex flex-col items-center justify-center text-center min-h-[180px]">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3">
                    <i class="fi fi-rr-credit-card"></i>
                </span>
                <h4 class="text-sm font-semibold text-slate-700 mb-1">Paystack</h4>
                <p class="text-xs text-slate-400 mb-3">Accept debit/credit card payments</p>
                <button onclick="document.getElementById('connectGatewayModal').classList.remove('hidden');" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors"><i class="fi fi-rr-plus text-xs"></i> Connect</button>
            </div>
            @endif

            {{-- Bank Transfer Card --}}
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 shrink-0">
                            <i class="fi fi-rr-building text-xs"></i>
                        </span>
                        <div>
                            <h4 class="text-sm font-semibold text-slate-800">Bank Transfer</h4>
                            <p class="text-xs text-slate-400">Manual payment via direct bank deposit</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-600 ring-1 ring-inset ring-blue-600/20">Always On</span>
                </div>
                <p class="text-xs text-slate-400">Bank accounts are managed per-store below. Customers can pay via direct transfer and upload payment slips.</p>
            </div>
        </div>
    </div>

    {{-- Bank Accounts --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Bank Accounts</h3>
                <p class="text-xs text-slate-400 mt-0.5">Per-store accounts for receiving bank transfers</p>
            </div>
            <button onclick="document.getElementById('addBankModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800 transition-colors">
                <i class="fi fi-rr-plus text-xs"></i> Add Bank
            </button>
        </div>
        <x-management.data-table>
            <x-slot:header>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Bank</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Account Details</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Store</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Primary</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
            </x-slot:header>
            @forelse($storeBanks as $bank)
            <tr class="hover:bg-slate-50/50 transition-colors">
                <td class="px-5 py-3"><span class="text-sm font-medium text-slate-800">{{ $bank->bank_name }}</span></td>
                <td class="px-5 py-3 hidden sm:table-cell">
                    <p class="text-sm text-slate-600">{{ $bank->account_number }}</p>
                    <p class="text-xs text-slate-400">{{ $bank->account_name }}</p>
                </td>
                <td class="px-5 py-3 hidden md:table-cell"><span class="text-sm text-slate-600">{{ $bank->store?->name ?? '—' }}</span></td>
                <td class="px-5 py-3 text-center">
                    @if($bank->is_primary)<span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600">Primary</span>
                    @else <span class="text-xs text-slate-300">—</span> @endif
                </td>
                <td class="px-5 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <button onclick="openEditBankModal(@js($bank->only(['id','bank_name','account_number','account_name','store_id','is_primary'])))" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit"><i class="fi fi-rr-edit text-xs"></i></button>
                        <button onclick="openDeleteBankModal(@js($bank->only(['id','bank_name'])))" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete"><i class="fi fi-rr-trash text-xs"></i></button>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">No bank accounts yet. Add one to start receiving payments.</td></tr>
            @endforelse
        </x-management.data-table>
    </div>
</div>

{{-- Connect Gateway Modal --}}
<div id="connectGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('connectGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Connect Paystack</h3>
                <button onclick="document.getElementById('connectGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('management.payment-settings.gateways.store') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="gateway" value="paystack">
                <p class="text-sm text-slate-500">Enter your Paystack API keys. These are encrypted at rest and only used for payment processing.</p>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Public Key</label>
                    <input type="text" name="public_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="pk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secret Key</label>
                    <input type="password" name="secret_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="sk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save &amp; Connect</button>
                    <button type="button" onclick="document.getElementById('connectGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
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
                <button onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form id="editGatewayForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <p class="text-sm text-slate-500">Current keys: <span id="editCurrPub" class="font-mono text-slate-600"></span> / <span id="editCurrSec" class="font-mono text-slate-600"></span></p>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Public Key</label>
                    <input type="text" name="public_key" id="editPubKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="pk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Secret Key</label>
                    <input type="password" name="secret_key" id="editSecKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm font-mono" placeholder="sk_test_xxxxxxxxxxxxxxxx" required>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update Keys</button>
                    <button type="button" onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Test Result Toast --}}
<div id="testResultToast" class="hidden fixed bottom-6 right-6 z-50 max-w-sm bg-white rounded-xl shadow-lg border p-4">
    <div class="flex items-start gap-3">
        <span id="testResultIcon"></span>
        <div>
            <p id="testResultTitle" class="text-sm font-semibold"></p>
            <p id="testResultBody" class="text-xs text-slate-500 mt-0.5"></p>
        </div>
        <button onclick="this.parentElement.parentElement.classList.add('hidden')" class="p-1 text-slate-400 hover:text-slate-600 ml-2 shrink-0">&times;</button>
    </div>
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
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Bank <span class="text-red-500">*</span></label>
                    <select name="bank_code" id="add_bank_code" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                        <option value="">Select bank</option>
                        @foreach($banks as $bank)
                        <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="bank_name" id="add_bank_name">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Number <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <input type="text" name="account_number" id="add_account_number" maxlength="10" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="10-digit account number" required>
                        <button type="button" id="verifyBankBtn" class="px-4 py-2 bg-slate-100 text-sm font-medium rounded-lg hover:bg-slate-200 transition-colors disabled:opacity-50">Verify</button>
                    </div>
                    <p id="verifyFeedback" class="text-xs"></p>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Account Name</label>
                    <input type="text" name="account_name" id="add_account_name" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm bg-slate-50" placeholder="Auto-filled after verification" readonly>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Store <span class="text-red-500">*</span></label>
                    <select name="store_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                        <option value="">Select store</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
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
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Store</label>
                    <select name="store_id" id="editBankStore" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                        <option value="">Unassigned</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <input type="hidden" name="is_primary" value="0">
                    <input type="checkbox" name="is_primary" id="editBankPrimary" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <label for="editBankPrimary" class="text-sm text-slate-700">Set as primary</label>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update Account</button>
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
            <p class="text-sm text-slate-500 mb-4">Delete <strong id="deleteBankName"></strong>?</p>
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
@endsection

@push('scripts')
<script>
let editingGatewayId = null;

window.openEditBankModal = function(bank) {
    document.getElementById('editBankForm').action = '/management/payment-settings/bank-accounts/' + bank.id;
    document.getElementById('editBankName').value = bank.bank_name || '';
    document.getElementById('editBankAcct').value = bank.account_number || '';
    document.getElementById('editBankAcctName').value = bank.account_name || '';
    document.getElementById('editBankStore').value = bank.store_id || '';
    document.getElementById('editBankPrimary').checked = bank.is_primary || false;
    document.getElementById('editBankModal').classList.remove('hidden');
};

window.openDeleteBankModal = function(bank) {
    document.getElementById('deleteBankForm').action = '/management/payment-settings/bank-accounts/' + bank.id;
    document.getElementById('deleteBankName').textContent = bank.bank_name || 'this account';
    document.getElementById('deleteBankModal').classList.remove('hidden');
};

window.openEditGateway = function(gw) {
    editingGatewayId = gw.id;
    document.getElementById('editCurrPub').textContent = gw.masked_public_key;
    document.getElementById('editCurrSec').textContent = gw.masked_secret_key;
    document.getElementById('editGatewayForm').action = '/management/payment-settings/gateways/' + gw.id;
    document.getElementById('editGatewayModal').classList.remove('hidden');
};

// Bank dropdown wiring + verification
document.addEventListener('DOMContentLoaded', function() {
    const bankSelect = document.getElementById('add_bank_code');
    const bankNameInput = document.getElementById('add_bank_name');
    const accountNumberInput = document.getElementById('add_account_number');
    const accountNameInput = document.getElementById('add_account_name');
    const verifyBtn = document.getElementById('verifyBankBtn');
    const verifyFeedback = document.getElementById('verifyFeedback');
    const submitBtn = document.getElementById('addBankSubmit');
    let verified = false;

    if (bankSelect) {
        bankSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            bankNameInput.value = opt.dataset.name || '';
            resetVerification();
        });
    }
    if (accountNumberInput) {
        accountNumberInput.addEventListener('input', function() {
            resetVerification();
            if (this.value.length === 10 && bankSelect?.value) {
                doVerify();
            }
        });
    }
    function resetVerification() {
        if (accountNameInput) accountNameInput.value = '';
        if (submitBtn) submitBtn.disabled = true;
        if (verifyFeedback) { verifyFeedback.textContent = ''; verifyFeedback.className = 'text-xs'; }
        verified = false;
    }

    async function doVerify() {
        const bankCode = bankSelect?.value;
        const accountNumber = accountNumberInput?.value;
        if (!bankCode || (accountNumber?.length !== 10)) {
            if (verifyFeedback) { verifyFeedback.textContent = 'Select a bank and enter a 10-digit account number.'; verifyFeedback.className = 'text-xs text-red-500'; }
            return;
        }
        if (verifyBtn) { verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying...'; }
        try {
            const resp = await fetch('{{ route("management.payment-settings.verify-bank") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify({ bank_code: bankCode, account_number: accountNumber })
            });
            const data = await resp.json();
            if (data.success && data.account_name) {
                if (accountNameInput) accountNameInput.value = data.account_name;
                if (submitBtn) submitBtn.disabled = false;
                if (verifyFeedback) { verifyFeedback.textContent = 'Account verified!'; verifyFeedback.className = 'text-xs text-emerald-600'; }
                verified = true;
            } else {
                if (verifyFeedback) { verifyFeedback.textContent = data.message || 'Could not verify account.'; verifyFeedback.className = 'text-xs text-red-500'; }
            }
        } catch (e) {
            if (verifyFeedback) { verifyFeedback.textContent = 'Verification failed. Try again.'; verifyFeedback.className = 'text-xs text-red-500'; }
        } finally {
            if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify'; }
        }
    }

    if (verifyBtn) {
        verifyBtn.addEventListener('click', doVerify);
    }
});

window.testGateway = function(id) {
    const btn = document.getElementById('testKeysBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fi fi-rr-spinner animate-spin text-xs"></i> Testing...';
    btn.disabled = true;

    fetch('/management/payment-settings/gateways/' + id + '/test', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        const toast = document.getElementById('testResultToast');
        const icon = document.getElementById('testResultIcon');
        const title = document.getElementById('testResultTitle');
        const body = document.getElementById('testResultBody');

        if (data.success) {
            icon.innerHTML = '<span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-600"><i class="fi fi-rr-check"></i></span>';
            title.textContent = 'Connected!';
            title.className = 'text-sm font-semibold text-emerald-700';
            body.textContent = data.message;
        } else {
            icon.innerHTML = '<span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-600"><i class="fi fi-rr-cross-circle"></i></span>';
            title.textContent = 'Connection Failed';
            title.className = 'text-sm font-semibold text-red-700';
            body.textContent = data.message;
        }
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 5000);
    })
    .catch(err => {
        alert('Test failed: ' + err.message);
    })
    .finally(() => {
        btn.innerHTML = orig;
        btn.disabled = false;
    });
};

window.configureWebhook = function(id) {
    if (!confirm('This will register a webhook URL with Paystack. Continue?')) return;
    fetch('/management/payment-settings/gateways/' + id + '/webhook', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Webhook configured! Refresh the page to see the update.');
            location.reload();
        } else {
            alert('Webhook setup failed: ' + data.message);
        }
    })
    .catch(err => alert('Error: ' + err.message));
};
</script>
@endpush
