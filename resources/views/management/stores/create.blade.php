@extends('management.layout')
@section('subtitle', 'Create Store')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Create a Store" subtitle="Set up your online storefront and start selling" />

<div class="w-full">
    <x-management.card>
        <form action="{{ route('management.stores.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6 p-2">
            @csrf

            {{-- Logo --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Store Logo</label>
                <div class="flex items-center gap-4">
                    <img id="logoPreview" src="{{ asset('vendor_files/assets/images/default-store-icon.png') }}" class="w-14 h-14 rounded-xl border border-slate-200 object-cover bg-slate-50 shrink-0">
                    <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp" class="hidden">
                    <button type="button" onclick="document.getElementById('logo').click()" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                        <i class="fi fi-rr-camera text-xs"></i> Upload Logo
                    </button>
                    @error('logo')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    <p class="text-xs text-slate-400">PNG, JPG, or WEBP. Max 2MB.</p>
                </div>
            </div>

            <hr class="border-slate-100">

            {{-- Store Model --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-3">Store Model</label>
                <div class="flex gap-3">
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:border-color .15s" onmouseenter="this.style.borderColor='#94a3b8'" onmouseleave="this.style.borderColor='#e2e8f0'">
                        <input type="checkbox" name="is_physical" value="1" onchange="toggleModelFields()"
                               style="width:16px;height:16px;accent-color:#0f172a;cursor:pointer;margin:0;flex-shrink:0"
                               {{ old('is_physical') ? 'checked' : '' }}>
                        <span style="font-size:13px;font-weight:500;color:#334155;white-space:nowrap">Physical Store</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:border-color .15s" onmouseenter="this.style.borderColor='#94a3b8'" onmouseleave="this.style.borderColor='#e2e8f0'">
                        <input type="checkbox" name="has_website" value="1" onchange="toggleModelFields()"
                               style="width:16px;height:16px;accent-color:#0f172a;cursor:pointer;margin:0;flex-shrink:0"
                               {{ old('has_website') ? 'checked' : '' }}>
                        <span style="font-size:13px;font-weight:500;color:#334155;white-space:nowrap">Online Storefront</span>
                    </label>
                </div>
            </div>

            {{-- Store Name --}}
            <x-management.form-input name="name" label="Store Name" :value="old('name')" placeholder="eg: Swift Essentials" required :error="$errors->first('name')" />

            {{-- Slug Preview (conditional: online) --}}
            <div id="slugPreview" class="hidden p-3 bg-slate-50 border border-slate-200 rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fi fi-rr-globe text-slate-400 text-sm shrink-0"></i>
                    <span id="slugUrl" class="text-sm font-medium text-slate-700 truncate">your-store.{{ config('app.main_domain', 'storify.ng') }}</span>
                    <span id="slugStatus" class="text-xs font-medium shrink-0"></span>
                    <span id="slugSpinner" class="hidden text-xs text-slate-400 shrink-0">Checking...</span>
                </div>
                <input type="hidden" name="slug" id="slugInput" value="">
            </div>

            {{-- Physical Address (conditional) --}}
            <div id="physicalAddressField" class="hidden">
                <x-management.form-input name="physical_address" label="Physical Address" :value="old('physical_address')" placeholder="eg: 12 Clifford Street, Lagos" />
            </div>

            {{-- Description --}}
            <div>
                <label for="description" class="block text-sm font-medium text-slate-700 mb-1.5">About Your Store</label>
                <textarea id="description" name="description" rows="3"
                    placeholder="Tell customers what you sell and what makes your brand special"
                    class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm @error('description') border-red-300 @enderror">{{ old('description') }}</textarea>
                @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            <hr class="border-slate-100">

            {{-- Contact Info --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-management.form-input name="support_email" label="Store Email" type="email" :value="old('support_email')" placeholder="support@yourbrand.com" />
                <x-management.form-input name="support_phone" label="Store Phone" :value="old('support_phone')" placeholder="0800 000 0000" />
            </div>

            <x-management.form-input name="address" label="Business Address" :value="old('address')" placeholder="eg: 12 Clifford Street, Lagos" />

            {{-- Currency --}}
            <x-management.form-input name="currency_id" label="Currency" type="select">
                <option value="">Select currency</option>
                @foreach($currencies as $currency)
                    <option value="{{ $currency->id }}" @selected(old('currency_id') == $currency->id)>
                        {{ $currency->code }} ({{ $currency->symbol }})
                    </option>
                @endforeach
            </x-management.form-input>

            <hr class="border-slate-100">

            {{-- Bank Account Assignment --}}
            @if($userBanks->count() > 0)
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-slate-800">Assign Bank Account</h3>
                    <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — add bank accounts from Payment Settings</span>
                </div>
                <select name="bank_id" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                    <option value="">None</option>
                    @foreach($userBanks as $bank)
                    <option value="{{ $bank->id }}" @selected(old('bank_id') == $bank->id)>
                        {{ $bank->bank_name }} — {{ $bank->account_number }} ({{ $bank->account_name }})
                        @if($bank->store_id) · {{ $bank->store->name }} @endif
                    </option>
                    @endforeach
                </select>
            </div>
            <hr class="border-slate-100">
            @endif

            {{-- Staff Assignment --}}
            @if($activeStaff->count() > 0)
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-slate-800">Assign Staff</h3>
                    <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — manage later from Staff page</span>
                </div>
                <select name="staff_ids[]" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">
                    <option value="">None</option>
                    @foreach($activeStaff as $member)
                    <option value="{{ $member->id }}" @selected(in_array($member->id, old('staff_ids', [])))>
                        {{ $member->name }} — {{ $member->roles->pluck('name')->join(', ') ?: 'No role' }}
                    </option>
                    @endforeach
                </select>
            </div>
            <hr class="border-slate-100">
            @endif

            {{-- Social Links --}}
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                    <i class="fi fi-rr-plus text-xs"></i> Social Media Links (optional)
                </button>
                <div x-show="open" x-collapse class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-4">
                    <x-management.form-input name="instagram_url" label="Instagram URL" :value="old('instagram_url')" placeholder="https://instagram.com/yourbrand" />
                    <x-management.form-input name="facebook_url" label="Facebook URL" :value="old('facebook_url')" placeholder="https://facebook.com/yourbrand" />
                    <x-management.form-input name="twitter_url" label="Twitter URL" :value="old('twitter_url')" placeholder="https://twitter.com/yourbrand" />
                    <x-management.form-input name="tiktok_url" label="TikTok URL" :value="old('tiktok_url')" placeholder="https://tiktok.com/@yourbrand" />
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-6 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    <!-- <i class="fi fi-rr-save text-xs"></i>  -->
                     Create Store
                </button>
                <a href="{{ route('management.dashboard') }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>

{{-- Explainer Modal --}}
<div id="explainerModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('explainerModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-2">Online Storefront</h3>
            <p class="text-sm text-slate-500 mb-4">
                Enabling an online storefront means your products will be available for purchase on the internet at your store's unique URL. Customers can browse your catalog, add items to their cart, and place orders directly from your website.
            </p>
            <button onclick="document.getElementById('explainerModal').classList.add('hidden')" class="w-full py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Got it</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let slugDebounceTimer = null;

function toggleModelFields() {
    var online = document.querySelector('input[name="has_website"]').checked;
    var physical = document.querySelector('input[name="is_physical"]').checked;
    var slugPreview = document.getElementById('slugPreview');
    var physicalField = document.getElementById('physicalAddressField');
    slugPreview.classList.toggle('hidden', !online);
    physicalField.classList.toggle('hidden', !physical);
    if (online) checkSlugAvailability();
}

function checkSlugAvailability() {
    var name = document.querySelector('input[name="name"]').value.trim();
    if (name.length < 2) return;

    clearTimeout(slugDebounceTimer);
    document.getElementById('slugSpinner').classList.remove('hidden');
    document.getElementById('slugStatus').textContent = '';

    slugDebounceTimer = setTimeout(function () {
        fetch('/management/stores/check-slug', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ name: name })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('slugSpinner').classList.add('hidden');
            document.getElementById('slugUrl').textContent = data.url;
            document.getElementById('slugInput').value = data.slug || '';
            if (data.available) {
                document.getElementById('slugStatus').innerHTML = '<span class="text-emerald-600 font-medium">✓ Available</span>';
            } else if (data.slug) {
                document.getElementById('slugStatus').textContent = 'Suggested: ' + data.slug;
            }
        })
        .catch(function () {
            document.getElementById('slugSpinner').classList.add('hidden');
        });
    }, 500);
}

document.addEventListener('DOMContentLoaded', function () {
    toggleModelFields();

    var nameInput = document.querySelector('input[name="name"]');
    if (nameInput) {
        nameInput.addEventListener('input', function () {
            if (document.querySelector('input[name="has_website"]').checked) {
                checkSlugAvailability();
            }
        });
    }

    var logoInput = document.getElementById('logo');
    var logoPreview = document.getElementById('logoPreview');
    if (logoInput) {
        logoInput.addEventListener('change', function () {
            var file = this.files[0];
            if (file) {
                var reader = new FileReader();
                reader.onload = function (e) { logoPreview.src = e.target.result; };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>
@endpush
