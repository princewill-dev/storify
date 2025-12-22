@extends('admin.layout')
@section('subtitle', 'Advanced settings')

@section('content')

<!-- Start - Account Header -->
<div class="card">
    <div class="card-footer py-0 d-flex align-items-center mx-sm-4 px-0 border-0">
        <ul class="nav nav-underline w-100 justify-content-start border-bottom" id="settingsTabs" role="tablist" style="overflow: visible;">
            <li class="nav-item" role="presentation">
                <a class="nav-link active" id="tab-general" data-bs-toggle="tab" href="#pane-general" role="tab" aria-controls="pane-general" aria-selected="true">General info</a>
            </li>
            <!-- <li class="nav-item" role="presentation">
                <a class="nav-link" id="tab-api" data-bs-toggle="tab" href="#pane-api" role="tab" aria-controls="pane-api" aria-selected="false">API Keys</a>
            </li> -->
            <li class="nav-item" role="presentation">
                <a class="nav-link" id="tab-seo" data-bs-toggle="tab" href="#pane-seo" role="tab" aria-controls="pane-seo" aria-selected="false">SEO Settings</a>
            </li>
        </ul>
    </div>
</div>
<!-- End - Account Header -->

<form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" class="mt-2">
    @csrf
    <div class="tab-content" id="settingsTabContent">
        <div class="tab-pane fade show active" id="pane-general" role="tabpanel" aria-labelledby="tab-general">
            <div class="row">
                <div class="col-xxl-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title">Basic Info</h6>
                        </div>
                        <div class="card-body">

                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Company Logo</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="d-flex align-items-center">
                                        <div class="me-4 mb-3 mb-lg-0 rounded-4 border" style="width: 200px; height: 100px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff;">
                                            <img src="{{ isset($settings) && $settings->company_logo_path ? asset('storage/' . $settings->company_logo_path) : asset('assets/images/avatar/middle/avatar2.webp') }}" alt="Company logo" id="logoPreview" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                        </div>
                                        <div>
                                            <input type="file" name="company_logo" class="form-control" accept=".png, .jpg, .jpeg, .webp" onchange="previewLogo(event)">
                                            <small class="text-muted">PNG, JPG, or WEBP. Max 2MB.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Favicon</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="d-flex align-items-center">
                                        <div class="me-4 mb-3 mb-lg-0 rounded-4 border" style="width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff;">
                                            <img src="{{ isset($settings) && $settings->company_favicon_path ? asset('storage/' . $settings->company_favicon_path) : asset('vendor_files/assets/images/favicon.png') }}" alt="Favicon" id="faviconPreview" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                        </div>
                                        <div>
                                            <input type="file" name="company_favicon" class="form-control" accept=".ico, .png" onchange="previewFavicon(event)">
                                            <small class="text-muted">ICO or PNG (recommended 32x32 or 48x48). Max 512KB.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Company Certificate</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="d-flex align-items-center">
                                        <div class="me-4 mb-3 mb-lg-0 rounded-4 border d-flex align-items-center justify-content-center" style="width: 200px; height: 120px; overflow: hidden; background: #fff;">
                                            @if($certificateUrl && $certificateIsPdf)
                                                <a href="{{ $certificateUrl }}" target="_blank" class="btn btn-outline-primary btn-sm">View PDF</a>
                                            @elseif($certificateUrl)
                                                <img src="{{ $certificateUrl }}" alt="Company certificate" id="certificatePreview" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                            @else
                                                <span class="text-muted">No certificate uploaded</span>
                                            @endif
                                        </div>
                                        <div>
                                            <input type="file" name="company_certificate" class="form-control" accept=".pdf, .jpg, .jpeg, .png, .webp">
                                            <small class="text-muted">PDF or Image (JPG, PNG, WEBP). Max 5MB.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Company Name</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $settings->company_name ?? '') }}" placeholder="Your Company Ltd">
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Store Creation Limit</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="number" name="store_creation_limit" class="form-control" value="{{ old('store_creation_limit', $settings->store_creation_limit ?? 5) }}" min="1">
                                    <small class="text-muted">Maximum number of stores a vendor can create.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Homepage Store</label>
                                </div>
                                <div class="col-md-9">
                                    <select name="main_store_id" class="form-select">
                                        <option value="">Select store for homepage</option>
                                        @foreach(($stores ?? []) as $store)
                                            <option value="{{ $store->id }}" @selected(old('main_store_id', $settings->main_store_id ?? null) == $store->id)>{{ $store->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Products on the homepage will be populated from this store.</small>
                                </div>
                            </div>

                            <!-- Greeting pop up -->

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Company Description</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea name="company_description" class="form-control" rows="3" placeholder="Brief description about your company">{{ old('company_description', $settings->company_description ?? '') }}</textarea>
                                    <small class="text-muted">This will be shown in the greeting modal.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Default Currency</label>
                                </div>
                                <div class="col-md-9">
                                    <select name="default_currency_id" class="form-select">
                                        <option value="">Select default currency</option>
                                        @foreach(($currencies ?? []) as $cur)
                                            <option value="{{ $cur->id }}" @selected(old('default_currency_id', $defaultCurrencyId ?? null) == $cur->id)>
                                                {{ $cur->name }} ({{ $cur->code }}) {{ $cur->symbol ? ' - '.$cur->symbol : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">This sets the default currency used across the site.</small>
                                </div>
                            </div>

                            <!-- Greeting Modal Settings -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <h5 class="mb-3 mt-4">Greeting Modal Settings</h5>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Enable Greeting Modal</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="form-check form-switch">
                                        <input type="checkbox" name="greeting_modal_enabled" class="form-check-input" id="greetingModalEnabled" value="1" @checked(old('greeting_modal_enabled', $settings->greeting_modal_enabled ?? false))>
                                        <label class="form-check-label" for="greetingModalEnabled">Show greeting modal to visitors</label>
                                    </div>
                                    <small class="text-muted">Display a welcome modal with company information and services.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Modal Frequency</label>
                                </div>
                                <div class="col-md-9">
                                    <select name="greeting_modal_frequency" class="form-select">
                                        <option value="never" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'never')>Never</option>
                                        <option value="always" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'always')>Always (Every Page Load)</option>
                                        <option value="once_per_session" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_session')>Once Per Session</option>
                                        <option value="once_per_day" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_day')>Once Per Day</option>
                                        <option value="once_per_week" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_week')>Once Per Week</option>
                                        <option value="once_per_month" @selected(old('greeting_modal_frequency', $settings->greeting_modal_frequency ?? 'never') == 'once_per_month')>Once Per Month</option>
                                    </select>
                                    <small class="text-muted">Control how often the greeting modal appears to visitors.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Support Email</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="email" name="support_email" class="form-control" value="{{ old('support_email', $settings->support_email ?? '') }}" placeholder="support@company.com">
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Support Phone</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" name="support_phone" class="form-control" value="{{ old('support_phone', $settings->support_phone ?? '') }}" placeholder="+234 801 234 5678">
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Company Address</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea name="company_address" class="form-control" rows="3" placeholder="Main office address...">{{ old('company_address', $settings->company_address ?? '') }}</textarea>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Branch Address</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea name="branch_address" class="form-control" rows="3" placeholder="Branch office address...">{{ old('branch_address', $settings->branch_address ?? '') }}</textarea>
                                </div>
                            </div>

                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- <div class="tab-pane fade" id="pane-api" role="tabpanel" aria-labelledby="tab-api">
            <div class="row">
                <div class="col-xxl-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title">API Keys</h6>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">Keys</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle" id="apiKeysTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 30%">Name</th>
                                                    <th>Value</th>
                                                    <th style="width: 50px"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @if(is_array($apiKeys ?? null) && count($apiKeys))
                                                    @foreach($apiKeys as $k => $v)
                                                        <tr>
                                                            <td><input type="text" name="api_key_names[]" class="form-control" value="{{ $k }}" placeholder="Paystack"></td>
                                                            <td><input type="text" name="api_key_values[]" class="form-control" value="{{ $v }}" placeholder="sk_live_xxx"></td>
                                                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">Remove</button></td>
                                                        </tr>
                                                    @endforeach
                                                @endif
                                                    <tr>
                                                        <td><input type="text" name="api_key_names[]" class="form-control" placeholder="Paystack"></td>
                                                        <td><input type="text" name="api_key_values[]" class="form-control" placeholder="sk_live_xxx"></td>
                                                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeApiKeyRow(this)">&times;</button></td>
                                                    </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-light" onclick="addApiKeyRow()"><i class="fa fa-plus me-1"></i>Add API Key</button>
                                </div>
                            </div>
                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->

        <div class="tab-pane fade" id="pane-seo" role="tabpanel" aria-labelledby="tab-seo">
            <div class="row">
                <div class="col-xxl-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title">SEO & Open Graph</h6>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">OG Title</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="text" name="og_title" class="form-control" value="{{ old('og_title', $settings->og_title ?? '') }}" placeholder="Your site title for sharing">
                                    <small class="text-muted">Shown as the title when links are shared on social media.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">OG Description</label>
                                </div>
                                <div class="col-md-9">
                                    <textarea name="og_description" class="form-control" rows="3" placeholder="Concise description for sharing">{{ old('og_description', $settings->og_description ?? '') }}</textarea>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">OG Image</label>
                                </div>
                                <div class="col-md-9">
                                    <div class="d-flex align-items-center">
                                        <div class="me-4 mb-3 mb-lg-0 rounded-4 border" style="width: 260px; height: 136px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#fff;">
                                            <img src="{{ isset($settings) && $settings->og_image_path ? asset('storage/' . $settings->og_image_path) : asset('home/images/og-default.png') }}" alt="OG Image" id="ogImagePreview" style="max-width:100%; max-height:100%; object-fit:contain;">
                                        </div>
                                        <div>
                                            <input type="file" name="og_image" class="form-control" accept=".png, .jpg, .jpeg, .webp" onchange="previewOgImage(event)">
                                            <small class="text-muted">Recommended 1200x630px. Max 2MB.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">OG URL</label>
                                </div>
                                <div class="col-md-9">
                                    <input type="url" name="og_url" class="form-control" value="{{ old('og_url', $settings->og_url ?? url('/') ) }}" placeholder="https://example.com">
                                    <small class="text-muted">Canonical URL used in social previews.</small>
                                </div>
                            </div>

                            <div class="row align-items-center mb-4">
                                <div class="col-md-3">
                                    <label class="form-label mb-md-0">OG Type</label>
                                </div>
                                <div class="col-md-9">
                                    <select name="og_type" class="form-select">
                                        @php($ogType = old('og_type', $settings->og_type ?? 'website'))
                                        <option value="website" @selected($ogType==='website')>website</option>
                                        <option value="article" @selected($ogType==='article')>article</option>
                                        <option value="product" @selected($ogType==='product')>product</option>
                                    </select>
                                </div>
                            </div>

                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // Persist active tab across reloads using URL hash and localStorage
    (function() {
        const mapHashToPane = {
            '#general': '#pane-general',
            '#api_keys': '#pane-api',
        };
        const mapPaneToHash = {
            '#pane-general': '#general',
            '#pane-api': '#api_keys',
        };
        function showTabForHash(hash) {
            const targetPaneId = mapHashToPane[hash] || hash;
            const link = document.querySelector(`#settingsTabs a[href="${targetPaneId}"]`);
            if (link && window.bootstrap) {
                const tab = new bootstrap.Tab(link);
                tab.show();
                return true;
            }
            return false;
        }
        document.addEventListener('DOMContentLoaded', function() {
            const saved = localStorage.getItem('settingsActiveTab');
            const initialHash = window.location.hash;
            if (!showTabForHash(initialHash)) {
                if (saved) showTabForHash(saved);
            }
            document.querySelectorAll('#settingsTabs a[data-bs-toggle="tab"]').forEach(function(el) {
                el.addEventListener('shown.bs.tab', function(e) {
                    const href = e.target.getAttribute('href');
                    const hash = mapPaneToHash[href] || href;
                    localStorage.setItem('settingsActiveTab', hash);
                    if (history && history.replaceState) {
                        history.replaceState(null, '', hash);
                    } else {
                        window.location.hash = hash;
                    }
                });
            });
        });
    })();

    function addApiKeyRow() {
        const tbody = document.querySelector('#apiKeysTable tbody');
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="api_key_names[]" class="form-control" placeholder="Provider"></td>
            <td><input type="text" name="api_key_values[]" class="form-control" placeholder="api-key"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeApiKeyRow(this)">&times;</button></td>
        `;
        tbody.appendChild(tr);
    }
    function removeApiKeyRow(btn) {
        const tr = btn && btn.closest ? btn.closest('tr') : null;
        if (!tr) return;
        tr.remove();
    }
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
</script>

@endsection
