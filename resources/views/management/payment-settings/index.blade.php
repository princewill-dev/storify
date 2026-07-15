@extends('management.layout')
@section('subtitle', 'Payment Settings')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Payment Settings" subtitle="Manage payment methods for your business" />

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 mb-6">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">{{ session('error') }}</div>
@endif

<div class="flex items-center gap-3 mb-6">
    <button onclick="openAddMethodModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
        <i class="fi fi-rr-plus text-xs"></i> Add Payment Method
    </button>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Method</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Type</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Details</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Assigned Stores</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Status</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider w-12"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse($methods as $m)
            @php $cfg = json_decode($m->businesses->first()?->pivot?->config ?? '{}', true); @endphp
            <tr class="hover:bg-slate-50/50 transition-colors">
                <td class="px-5 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-8 h-8 rounded-lg {{ $m->type === 'gateway' ? 'bg-indigo-50 text-indigo-500' : 'bg-amber-50 text-amber-500' }} flex items-center justify-center shrink-0">
                            <i class="fi {{ $m->type === 'gateway' ? 'fi-rr-credit-card' : 'fi-rr-building-columns' }} text-sm"></i>
                        </span>
                        <span class="text-sm font-medium text-slate-800">{{ $m->name }}</span>
                    </div>
                </td>
                <td class="px-5 py-3"><span class="inline-flex items-center rounded-full {{ $m->type === 'gateway' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }} px-2 py-0.5 text-[11px] font-medium">{{ $m->type === 'gateway' ? 'Gateway' : 'Traditional' }}</span></td>
                <td class="px-5 py-3 hidden sm:table-cell">
                    @if($m->type === 'gateway')
                    <code class="text-xs text-slate-500">{{ substr($cfg['public_key'] ?? '', 0, 8) }}****</code>
                    @else
                    <span class="text-xs text-slate-500">{{ $cfg['account_number'] ?? $cfg['bank_name'] ?? $m->description }}</span>
                    @endif
                </td>
                <td class="px-5 py-3 text-center">
                    <a href="{{ route('management.payment-settings.method-info', ['type' => $m->type === 'gateway' ? 'gateway' : 'bank', 'id' => $m->businesses->first()?->pivot?->id]) }}" class="text-sm font-semibold text-slate-700 hover:text-blue-600">{{ $m->assigned_count ?? 0 }}</a>
                </td>
                <td class="px-5 py-3 text-center">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ ($m->businesses->first()?->pivot?->is_active ?? false) ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ ($m->businesses->first()?->pivot?->is_active ?? false) ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                        {{ ($m->businesses->first()?->pivot?->is_active ?? false) ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="px-5 py-3 text-right relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z"/></svg></button>
                    @php $pivotId = $m->businesses->first()?->pivot?->id; @endphp
                    <div x-show="open" x-transition class="absolute right-0 mt-1 w-40 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50">
                        <a href="{{ route('management.payment-settings.method-info', ['type' => $m->type === 'gateway' ? 'gateway' : 'bank', 'id' => $pivotId]) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"><i class="fi fi-rr-info w-4 text-slate-400"></i> View</a>
                        @if($m->type === 'gateway')
                        <button onclick="editGateway(@js(['id' => $pivotId]))" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 w-full text-left"><i class="fi fi-rr-edit w-4 text-slate-400"></i> Edit</button>
                        <form method="POST" action="{{ route('management.payment-settings.gateways.toggle', $pivotId) }}">
                            @csrf @method('PATCH')
                            <button class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 w-full text-left"><i class="fi fi-rr-power w-4 text-slate-400"></i> {{ ($m->businesses->first()?->pivot?->is_active ?? false) ? 'Disable' : 'Enable' }}</button>
                        </form>
                        @endif
                        <hr class="border-slate-100 my-1">
                        <button onclick="deleteMethod({{ $pivotId }})" class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i> Remove</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-slate-400">No payment methods configured yet. Click "Add Payment Method" to get started.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Modals (same as before, kept compact) --}}
<div id="addMethodModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('addMethodModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-1">Add Payment Method</h3>
            <p class="text-sm text-slate-500 mb-5">Choose the type of payment method</p>
            <div class="space-y-3">
                <button onclick="document.getElementById('addMethodModal').classList.add('hidden');document.getElementById('gatewayModal').classList.remove('hidden')" class="w-full flex items-center gap-4 p-4 border border-slate-200 rounded-xl hover:border-indigo-300 hover:bg-indigo-50/50 text-left">
                    <span class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-500 shrink-0"><i class="fi fi-rr-credit-card"></i></span>
                    <div><p class="text-sm font-semibold text-slate-800">Gateway</p><p class="text-xs text-slate-400">Connect Paystack for card payments</p></div>
                </button>
                <button onclick="document.getElementById('addMethodModal').classList.add('hidden');document.getElementById('bankModal').classList.remove('hidden')" class="w-full flex items-center gap-4 p-4 border border-slate-200 rounded-xl hover:border-amber-300 hover:bg-amber-50/50 text-left">
                    <span class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-500 shrink-0"><i class="fi fi-rr-building-columns"></i></span>
                    <div><p class="text-sm font-semibold text-slate-800">Traditional</p><p class="text-xs text-slate-400">Bank transfer — customers pay directly to your account</p></div>
                </button>
            </div>
            <button onclick="document.getElementById('addMethodModal').classList.add('hidden')" class="mt-4 w-full py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button>
        </div>
    </div>
</div>

<div id="gatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('gatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Connect Paystack</h3><button onclick="document.getElementById('gatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button></div>
            <form method="POST" action="{{ route('management.payment-settings.gateways.store') }}" class="p-6 space-y-4">
                @csrf
                <p class="text-sm text-slate-500">Enter your Paystack API keys.</p>
                <div><label class="block text-sm font-medium text-slate-700">Public Key</label><input type="text" name="public_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm font-mono" placeholder="pk_test_..." required></div>
                <div><label class="block text-sm font-medium text-slate-700">Secret Key</label><input type="password" name="secret_key" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm font-mono" placeholder="sk_test_..." required></div>
                <div class="flex items-center gap-3 pt-2"><button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Connect</button><button type="button" onclick="document.getElementById('gatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

<div id="bankModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('bankModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Add Bank Account</h3><button onclick="document.getElementById('bankModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button></div>
            <form method="POST" action="{{ route('management.payment-settings.bank-accounts.store') }}" class="p-6 space-y-4">
                @csrf
                <div><label class="block text-sm font-medium text-slate-700">Bank</label>
                    <select name="bank_code" id="bankCode" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" required onchange="document.getElementById('bankName').value=this.options[this.selectedIndex].dataset.name||''">
                        <option value="">Select bank</option>
                        @foreach($banks as $bank) <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option> @endforeach
                    </select>
                    <input type="hidden" name="bank_name" id="bankName"></div>
                <div><label class="block text-sm font-medium text-slate-700">Account Number</label>
                    <div class="flex gap-2"><input type="text" name="account_number" id="acctNumber" maxlength="10" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm" placeholder="10-digit" required><button type="button" id="verifyBtn" class="px-4 py-2 bg-slate-100 text-sm font-medium rounded-lg hover:bg-slate-200 disabled:opacity-50" onclick="verifyBank()">Verify</button></div>
                    <p id="verifyResult" class="text-xs mt-1"></p></div>
                <div><label class="block text-sm font-medium text-slate-700">Account Name</label><input type="text" name="account_name" id="acctName" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm bg-slate-50" placeholder="Auto-filled" readonly></div>
                <div><label class="block text-sm font-medium text-slate-700">Assign to Store</label>
                    <select name="store_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm"><option value="">Not assigned</option>@foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                <div class="flex items-center gap-3 pt-2"><button type="submit" id="saveBankBtn" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 disabled:opacity-50" disabled>Save</button><button type="button" onclick="document.getElementById('bankModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

<div id="editGatewayModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('editGatewayModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Edit Paystack Keys</h3><button onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button></div>
            <form id="editGatewayForm" method="POST" class="p-6 space-y-4">
                @csrf @method('PUT')
                <div><label class="block text-sm font-medium text-slate-700">Public Key</label><input type="text" name="public_key" id="editPubKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm font-mono" required></div>
                <div><label class="block text-sm font-medium text-slate-700">Secret Key</label><input type="password" name="secret_key" id="editSecKey" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm text-sm font-mono" required></div>
                <div class="flex items-center gap-3 pt-2"><button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Update</button><button type="button" onclick="document.getElementById('editGatewayModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openAddMethodModal(){document.getElementById('addMethodModal').classList.remove('hidden')}
function editGateway(gw){document.getElementById('editGatewayForm').action='/management/payment-settings/gateways/'+gw.id;document.getElementById('editGatewayModal').classList.remove('hidden')}
function deleteMethod(id){if(!confirm('Remove this payment method?'))return;const f=document.createElement('form');f.method='POST';f.action='/management/payment-settings/gateways/'+id;f.innerHTML='<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="DELETE">';document.body.appendChild(f);f.submit()}
function testGateway(id){fetch('/management/payment-settings/gateways/'+id+'/test',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}}).then(r=>r.json()).then(d=>{alert(d.success?'Connected!':'Failed: '+(d.message||'Error'))}).catch(()=>alert('Test failed'))}
function verifyBank(){const c=document.getElementById('bankCode').value,n=document.getElementById('acctNumber').value,b=document.getElementById('verifyBtn'),r=document.getElementById('verifyResult');if(!c||n.length!==10){r.textContent='Select bank and enter 10-digit number';r.className='text-xs text-red-500';return}b.disabled=true;b.textContent='Verifying...';fetch('{{ route("management.payment-settings.verify-bank") }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},body:JSON.stringify({bank_code:c,account_number:n})}).then(r=>r.json()).then(d=>{if(d.success&&d.account_name){document.getElementById('acctName').value=d.account_name;document.getElementById('saveBankBtn').disabled=false;r.textContent='Verified: '+d.account_name;r.className='text-xs text-emerald-600'}else{r.textContent=d.message||'Could not verify';r.className='text-xs text-red-500'}}).catch(()=>{r.textContent='Verification failed';r.className='text-xs text-red-500'}).finally(()=>{b.disabled=false;b.textContent='Verify'})}
document.getElementById('acctNumber')?.addEventListener('input',function(){document.getElementById('saveBankBtn').disabled=true;if(this.value.length===10)verifyBank()})
</script>
@endpush
