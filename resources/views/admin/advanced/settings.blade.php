@extends('admin.layout')
@section('subtitle', 'Advanced settings')

@section('content')

<div x-data="{ activeTab: 'general' }" x-init="
    const saved = localStorage.getItem('settingsActiveTab');
    const hash = window.location.hash.replace('#', '');
    const tabs = ['general', 'seo'];
    if (tabs.includes(hash)) activeTab = hash;
    else if (saved && tabs.includes(saved)) activeTab = saved;
">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="px-4 sm:px-6 border-b border-slate-100">
            <nav class="flex gap-0 -mb-px">
                <button @click="activeTab = 'general'; localStorage.setItem('settingsActiveTab', 'general'); history.replaceState(null, '', '#general')"
                    :class="activeTab === 'general' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors">General info</button>
                <button @click="activeTab = 'seo'; localStorage.setItem('settingsActiveTab', 'seo'); history.replaceState(null, '', '#seo')"
                    :class="activeTab === 'seo' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition-colors">SEO Settings</button>
            </nav>
        </div>
    </div>

    <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div x-show="activeTab === 'general'">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="flex items-center px-6 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-700">Basic Info</h3>
                </div>
                <div class="p-6 space-y-6">

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Company Logo</label>
                        </div>
                        <div class="md:col-span-3">
                            <div class="flex items-center gap-4">
                                <div class="shrink-0 rounded-xl border border-slate-200 overflow-hidden bg-white" style="width: 200px; height: 100px;">
                                    <img src="{{ isset($settings) && $settings->company_logo_path ? asset('storage/' . $settings->company_logo_path) : asset('assets/images/avatar/middle/avatar2.webp') }}" alt="Company logo" id="logoPreview" class="w-full h-full object-contain">
                                </div>
                                <div class="flex-1">
                                    <input type="file" name="company_logo" class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".png, .jpg, .jpeg, .webp" onchange="previewLogo(event)">
                                    <small class="text-slate-400">PNG, JPG, or WEBP. Max 2MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Favicon</label>
                        </div>
                        <div class="md:col-span-3">
                            <div class="flex items-center gap-4">
                                <div class="shrink-0 rounded-xl border border-slate-200 overflow-hidden bg-white" style="width: 64px; height: 64px;">
                                    <img src="{{ isset($settings) && $settings->company_favicon_path ? asset('storage/' . $settings->company_favicon_path) : asset('vendor_files/assets/images/favicon.png') }}" alt="Favicon" id="faviconPreview" class="w-full h-full object-contain">
                                </div>
                                <div class="flex-1">
                                    <input type="file" name="company_favicon" class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".ico, .png" onchange="previewFavicon(event)">
                                    <small class="text-slate-400">ICO or PNG (recommended 32x32 or 48x48). Max 512KB.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Company Certificate</label>
                        </div>
                        <div class="md:col-span-3">
                            <div class="flex items-center gap-4">
                                <div class="shrink-0 rounded-xl border border-slate-200 overflow-hidden bg-white flex items-center justify-center" style="width: 200px; height: 120px;">
                                    @if($certificateUrl && $certificateIsPdf)
                                        <a href="{{ $certificateUrl }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">View PDF</a>
                                    @elseif($certificateUrl)
                                        <img src="{{ $certificateUrl }}" alt="Company certificate" id="certificatePreview" class="w-full h-full object-contain">
                                    @else
                                        <span class="text-slate-400 text-sm">No certificate uploaded</span>
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <input type="file" name="company_certificate" class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".pdf, .jpg, .jpeg, .png, .webp">
                                    <small class="text-slate-400">PDF or Image (JPG, PNG, WEBP). Max 5MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Company Name</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="text" name="company_name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('company_name', $settings->company_name ?? '') }}" placeholder="Your Company Ltd">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Store Creation Limit</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="number" name="store_creation_limit" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('store_creation_limit', $settings->store_creation_limit ?? 5) }}" min="1">
                            <small class="text-slate-400">Maximum number of stores a user can create.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Free Trial</label>
                        </div>
                        <div class="md:col-span-3">
                            <label class="relative inline-flex items-center cursor-pointer mb-3">
                                <input type="checkbox" name="trial_enabled" id="trialEnabled" value="1" @checked(old('trial_enabled', $settings->trial_enabled ?? true)) class="sr-only peer" onchange="document.getElementById('trialDaysRow').style.display = this.checked ? 'flex' : 'none'">
                                <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-slate-600"></div>
                                <span class="ms-3 text-sm text-slate-700">Enable free trial for new users</span>
                            </label>
                            <div class="flex items-center gap-2" id="trialDaysRow" style="{{ old('trial_enabled', $settings->trial_enabled ?? true) ? '' : 'display:none' }}">
                                <label class="text-sm text-slate-700 text-nowrap">Duration:</label>
                                <input type="number" name="trial_days" class="w-24 rounded-lg border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('trial_days', $settings->trial_days ?? 7) }}" min="1" max="90">
                                <span class="text-sm text-slate-400">days</span>
                            </div>
                            <small class="text-slate-400">New businesses get a free trial period before being billed.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Homepage Store</label>
                        </div>
                        <div class="md:col-span-3">
                            <select name="main_store_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">Select store for homepage</option>
                                @foreach(($stores ?? []) as $store)
                                    <option value="{{ $store->id }}" @selected(old('main_store_id', $settings->main_store_id ?? null) == $store->id)>{{ $store->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-slate-400">Products on the homepage will be populated from this store.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Company Description</label>
                        </div>
                        <div class="md:col-span-3">
                            <textarea name="company_description" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Brief description about your company">{{ old('company_description', $settings->company_description ?? '') }}</textarea>
                            <small class="text-slate-400">This will be shown in the greeting modal.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Default Currency</label>
                        </div>
                        <div class="md:col-span-3">
                            <select name="default_currency_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">Select default currency</option>
                                @foreach(($currencies ?? []) as $cur)
                                    <option value="{{ $cur->id }}" @selected(old('default_currency_id', $defaultCurrencyId ?? null) == $cur->id)>
                                        {{ $cur->name }} ({{ $cur->code }}) {{ $cur->symbol ? ' - '.$cur->symbol : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-slate-400">This sets the default currency used across the site.</small>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <h4 class="text-base font-semibold text-slate-800 mb-4">Greeting Modal Settings</h4>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Enable Greeting Modal</label>
                            </div>
                            <div class="md:col-span-3">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="greeting_modal_enabled" id="greetingModalEnabled" value="1" @checked(old('greeting_modal_enabled', $settings->greeting_modal_enabled ?? false)) class="sr-only peer">
                                    <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-slate-600"></div>
                                    <span class="ms-3 text-sm text-slate-700">Show greeting modal to visitors</span>
                                </label>
                                <small class="text-slate-400 block mt-1">Display a welcome modal with company information and services.</small>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center mt-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Modal Frequency</label>
                            </div>
                            <div class="md:col-span-3">
                                <select name="greeting_modal_frequency" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                    <option value="never" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'never')>Never</option>
                                    <option value="always" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'always')>Always (Every Page Load)</option>
                                    <option value="once_per_session" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_session')>Once Per Session</option>
                                    <option value="once_per_day" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_day')>Once Per Day</option>
                                    <option value="once_per_week" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_week')>Once Per Week</option>
                                    <option value="once_per_month" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_month')>Once Per Month</option>
                                </select>
                                <small class="text-slate-400">Control how often the greeting modal appears to visitors.</small>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Support Email</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="email" name="support_email" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('support_email', $settings->support_email ?? '') }}" placeholder="support@company.com">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Support Phone</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="text" name="support_phone" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('support_phone', $settings->support_phone ?? '') }}" placeholder="+234 801 234 5678">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Company Address</label>
                        </div>
                        <div class="md:col-span-3">
                            <textarea name="company_address" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Main office address...">{{ old('company_address', $settings->company_address ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Branch Address</label>
                        </div>
                        <div class="md:col-span-3">
                            <textarea name="branch_address" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Branch office address...">{{ old('branch_address', $settings->branch_address ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeTab === 'seo'">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="flex items-center px-6 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-700">SEO &amp; Open Graph</h3>
                </div>
                <div class="p-6 space-y-6">

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">OG Title</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="text" name="og_title" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('og_title', $settings->og_title ?? '') }}" placeholder="Your site title for sharing">
                            <small class="text-slate-400">Shown as the title when links are shared on social media.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">OG Description</label>
                        </div>
                        <div class="md:col-span-3">
                            <textarea name="og_description" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" rows="3" placeholder="Concise description for sharing">{{ old('og_description', $settings->og_description ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">OG Image</label>
                        </div>
                        <div class="md:col-span-3">
                            <div class="flex items-center gap-4">
                                <div class="shrink-0 rounded-xl border border-slate-200 overflow-hidden bg-white" style="width: 260px; height: 136px;">
                                    <img src="{{ isset($settings) && $settings->og_image_path ? asset('storage/' . $settings->og_image_path) : asset('home/images/og-default.png') }}" alt="OG Image" id="ogImagePreview" class="w-full h-full object-contain">
                                </div>
                                <div class="flex-1">
                                    <input type="file" name="og_image" class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".png, .jpg, .jpeg, .webp" onchange="previewOgImage(event)">
                                    <small class="text-slate-400">Recommended 1200x630px. Max 2MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">OG URL</label>
                        </div>
                        <div class="md:col-span-3">
                            <input type="url" name="og_url" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('og_url', $settings->og_url ?? url('/') ) }}" placeholder="https://example.com">
                            <small class="text-slate-400">Canonical URL used in social previews.</small>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">OG Type</label>
                        </div>
                        <div class="md:col-span-3">
                            <select name="og_type" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                @php($ogType = old('og_type', $settings->og_type ?? 'website'))
                                <option value="website" @selected($ogType==='website')>website</option>
                                <option value="article" @selected($ogType==='article')>article</option>
                                <option value="product" @selected($ogType==='product')>product</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save Changes</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function previewLogo(e) {
        const file = e.target && e.target.files ? e.target.files[0] : null;
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev) {
            const img = document.getElementById('logoPreview');
            if (img) img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    }

    function previewFavicon(e) {
        const file = e.target && e.target.files ? e.target.files[0] : null;
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev) {
            const img = document.getElementById('faviconPreview');
            if (img) img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    }

    function previewOgImage(e) {
        const file = e.target && e.target.files ? e.target.files[0] : null;
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev) {
            const img = document.getElementById('ogImagePreview');
            if (img) img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    }
</script>

@endsection
