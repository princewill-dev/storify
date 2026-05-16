@extends('vendors.auth.layout')

@section('subtitle', 'Create your store')

@push('styles')
<style>
    .auth-card { width: min(680px, 100%); }
</style>
@endpush

@section('content')
    <div class="mb-4 text-center">
        <h3 class="fw-semibold mb-1">Let’s set up your store</h3>
        <!-- <p class="text-muted mb-0">Store Information</p> -->
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form action="{{ route('vendor.store.submit', ['vendor' => $vendor]) }}" method="POST" enctype="multipart/form-data" class="vstack gap-4">
                @csrf

                <div class="row g-4">
                
                    <div>
                        <!-- <label class="form-label fw-semibold">Store Logo</label> -->
                        <div class="d-inline-block position-relative" style="width: 150px;">
                            <div class="border rounded-4 overflow-hidden position-relative" style="width: 150px; height: 150px; background: #f4f4f5;">
                                <img id="logoPreview" src="{{ asset('vendor_files/assets/images/default-store-icon.png') }}" alt="Logo preview" style="width:100%; height:100%; object-fit:cover;">
                                <!-- Delete button -->
                                <button type="button" id="logoDeleteBtn" class="btn btn-light btn-sm position-absolute d-flex align-items-center justify-content-center shadow-sm" style="top: 8px; right: 8px; width: 32px; height: 32px; border-radius: 8px; padding: 0; display: none !important;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                                        <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                                    </svg>
                                </button>
                                <!-- Change Image button -->
                                <button type="button" id="logoChangeBtn" class="btn btn-dark btn-sm position-absolute" style="bottom: 10px; left: 50%; transform: translateX(-50%); white-space: nowrap; border-radius: 6px; font-size: 0.8rem; padding: 5px 14px;">
                                    Add Store Logo
                                </button>
                            </div>
                            <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp" class="d-none @error('logo') is-invalid @enderror">
                            <!-- <small class="text-muted d-block mt-2">PNG, JPG, or WEBP. Max 2MB.</small> -->
                            @error('logo')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="name" class="form-label fw-semibold">Store name<span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name"
                               class="form-control form-control-lg @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="eg: Swift Essentials" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        
                        <!-- Slug availability feedback -->
                        <div id="slugFeedback" class="mt-2" style="display: none;">
                            <small id="slugStatus" class="d-flex align-items-center gap-2"></small>
                        </div>
                        <input type="hidden" id="slug" name="slug" value="{{ old('slug') }}">
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label fw-semibold">About your store</label>
                        <textarea id="description" name="description" rows="3"
                                  class="form-control form-control-lg @error('description') is-invalid @enderror"
                                  placeholder="Tell customers what you sell and what makes your brand special">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="support_email" class="form-label fw-semibold">Store email</label>
                        <input type="email" id="support_email" name="support_email"
                               class="form-control form-control-lg @error('support_email') is-invalid @enderror"
                               value="{{ old('support_email') }}" placeholder="support@yourbrand.com">
                        @error('support_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="support_phone" class="form-label fw-semibold">Store phone</label>
                        <input type="text" id="support_phone" name="support_phone"
                               class="form-control form-control-lg @error('support_phone') is-invalid @enderror"
                               value="{{ old('support_phone') }}" placeholder="0800 000 0000">
                        @error('support_phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12">
                        <label for="address" class="form-label fw-semibold">Store address</label>
                        <input type="text" id="address" name="address"
                               class="form-control form-control-lg @error('address') is-invalid @enderror"
                               value="{{ old('address') }}" placeholder="eg: 12 Clifford Street" />
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="ownership_type_id" class="form-label fw-semibold">Ownership type</label>
                        <select id="ownership_type_id" name="ownership_type_id" class="form-select form-select-lg @error('ownership_type_id') is-invalid @enderror">
                            <option value="" @selected(!old('ownership_type_id')) disabled>Select ownership type</option>
                            @foreach($ownershipTypes as $type)
                                <option value="{{ $type->id }}" @selected(old('ownership_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('ownership_type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="business_type_id" class="form-label fw-semibold">Business type</label>
                        <select id="business_type_id" name="business_type_id" class="form-select form-select-lg @error('business_type_id') is-invalid @enderror">
                            <option value="" @selected(!old('business_type_id')) disabled>Select business type</option>
                            @foreach($businessTypes as $type)
                                <option value="{{ $type->id }}" @selected(old('business_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('business_type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Social media — hidden inputs carry values, modal for editing --}}
                <input type="hidden" name="instagram_url" id="instagram_url_hidden" value="{{ old('instagram_url') }}">
                <input type="hidden" name="facebook_url"  id="facebook_url_hidden"  value="{{ old('facebook_url') }}">
                <input type="hidden" name="twitter_url"   id="twitter_url_hidden"   value="{{ old('twitter_url') }}">
                <input type="hidden" name="tiktok_url"    id="tiktok_url_hidden"    value="{{ old('tiktok_url') }}">

                <div>
                    <label class="form-label fw-semibold">Social Media Links <span class="text-muted fw-normal">(optional)</span></label>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-outline-dark btn-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#socialLinksModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>
                            Add Social Media Links
                        </button>
                        <div id="socialBadges" class="d-flex flex-wrap gap-2"></div>
                    </div>
                    @error('instagram_url')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('facebook_url')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('twitter_url')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @error('tiktok_url')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>

                {{-- Social Links Modal --}}
                <div class="modal fade" id="socialLinksModal" tabindex="-1" aria-labelledby="socialLinksModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header border-bottom-0 pb-0">
                                <h5 class="modal-title fw-semibold" id="socialLinksModalLabel">Social Media Links</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body pt-2 vstack gap-3">
                                <p class="text-muted small mb-0">Add your social media pages so customers can find you.</p>

                                <div>
                                    <label for="modal_instagram" class="form-label fw-medium small mb-1">Instagram</label>
                                    <input type="url" id="modal_instagram" class="form-control" placeholder="https://www.instagram.com/yourhandle" value="{{ old('instagram_url') }}">
                                </div>
                                <div>
                                    <label for="modal_facebook" class="form-label fw-medium small mb-1">Facebook</label>
                                    <input type="url" id="modal_facebook" class="form-control" placeholder="https://www.facebook.com/yourpage" value="{{ old('facebook_url') }}">
                                </div>
                                <div>
                                    <label for="modal_twitter" class="form-label fw-medium small mb-1">Twitter / X</label>
                                    <input type="url" id="modal_twitter" class="form-control" placeholder="https://twitter.com/yourhandle" value="{{ old('twitter_url') }}">
                                </div>
                                <div>
                                    <label for="modal_tiktok" class="form-label fw-medium small mb-1">TikTok</label>
                                    <input type="url" id="modal_tiktok" class="form-control" placeholder="https://www.tiktok.com/@yourhandle" value="{{ old('tiktok_url') }}">
                                </div>
                            </div>
                            <div class="modal-footer border-top-0 pt-0">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-dark" id="saveSocialLinks">Save Links</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" id="submitBtn" class="btn btn-dark btn-lg px-4" disabled>
                        <span id="submitBtnText">Save &amp; continue</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Logo upload
            const logoInput = document.getElementById('logo');
            const preview = document.getElementById('logoPreview');
            const changeBtn = document.getElementById('logoChangeBtn');
            const deleteBtn = document.getElementById('logoDeleteBtn');
            const defaultSrc = '{{ asset("vendor_files/assets/images/default-store-icon.png") }}';

            if (changeBtn) {
                changeBtn.addEventListener('click', function () {
                    logoInput.click();
                });
            }

            if (logoInput && preview) {
                logoInput.addEventListener('change', function (event) {
                    const file = event.target.files?.[0];
                    if (!file) return;
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        preview.src = e.target?.result ?? defaultSrc;
                        deleteBtn.style.cssText = deleteBtn.style.cssText.replace('display: none !important', '');
                    };
                    reader.readAsDataURL(file);
                });
            }

            if (deleteBtn) {
                deleteBtn.addEventListener('click', function () {
                    preview.src = defaultSrc;
                    logoInput.value = '';
                    deleteBtn.style.cssText += 'display: none !important;';
                });
            }

            // Social media modal
            const socialMap = {
                instagram: { modal: 'modal_instagram', hidden: 'instagram_url_hidden', label: 'Instagram', icon: '📷' },
                facebook:  { modal: 'modal_facebook',  hidden: 'facebook_url_hidden',  label: 'Facebook',  icon: '📘' },
                twitter:   { modal: 'modal_twitter',   hidden: 'twitter_url_hidden',   label: 'Twitter / X', icon: '🐦' },
                tiktok:    { modal: 'modal_tiktok',    hidden: 'tiktok_url_hidden',    label: 'TikTok',    icon: '🎵' },
            };
            const badgesContainer = document.getElementById('socialBadges');

            function renderSocialBadges() {
                badgesContainer.innerHTML = '';
                Object.entries(socialMap).forEach(([key, cfg]) => {
                    const val = document.getElementById(cfg.hidden).value.trim();
                    if (!val) return;
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-light text-dark border d-inline-flex align-items-center gap-1';
                    badge.style.cssText = 'font-size:.8rem; padding:5px 10px; border-radius:6px; cursor:default;';
                    badge.innerHTML = `${cfg.icon} ${cfg.label} <button type="button" class="btn-close btn-close-sm ms-1" style="font-size:.55rem;" data-social-key="${key}"></button>`;
                    badge.querySelector('button').addEventListener('click', function () {
                        document.getElementById(cfg.hidden).value = '';
                        document.getElementById(cfg.modal).value = '';
                        renderSocialBadges();
                    });
                    badgesContainer.appendChild(badge);
                });
            }

            document.getElementById('saveSocialLinks')?.addEventListener('click', function () {
                Object.values(socialMap).forEach(cfg => {
                    document.getElementById(cfg.hidden).value = document.getElementById(cfg.modal).value.trim();
                });
                renderSocialBadges();
                bootstrap.Modal.getInstance(document.getElementById('socialLinksModal'))?.hide();
            });

            // Pre-populate badges on page load (e.g. after validation error)
            renderSocialBadges();

            // Slug availability checker
            const nameInput = document.getElementById('name');
            const slugInput = document.getElementById('slug');
            const slugFeedback = document.getElementById('slugFeedback');
            const slugStatus = document.getElementById('slugStatus');
            const submitBtn = document.getElementById('submitBtn');
            const checkSlugUrl = '{{ route("vendor.store.check-slug", ["vendor" => $vendor]) }}';
            const csrfToken = '{{ csrf_token() }}';
            
            let debounceTimer = null;
            let isSlugValid = false;
            let currentAbortController = null;

            function setButtonState(enabled) {
                submitBtn.disabled = !enabled;
                submitBtn.classList.toggle('btn-dark', enabled);
                submitBtn.classList.toggle('btn-secondary', !enabled);
            }

            function showFeedback(type, message, suggestedSlug = null) {
                slugFeedback.style.display = 'block';
                
                let icon = '';
                let colorClass = '';
                let extraHtml = '';
                
                switch (type) {
                    case 'checking':
                        icon = '<span class="spinner-border spinner-border-sm" role="status"></span>';
                        colorClass = 'text-muted';
                        break;
                    case 'available':
                        icon = '<svg width="16" height="16" fill="currentColor" class="text-success" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>';
                        colorClass = 'text-success';
                        break;
                    case 'taken':
                        icon = '<svg width="16" height="16" fill="currentColor" class="text-danger" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/></svg>';
                        colorClass = 'text-danger';
                        if (suggestedSlug) {
                            extraHtml = ` <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="useSuggested" data-slug="${suggestedSlug}">Use "${suggestedSlug}" instead</button>`;
                        }
                        break;
                    case 'error':
                        icon = '<svg width="16" height="16" fill="currentColor" class="text-warning" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>';
                        colorClass = 'text-warning';
                        break;
                }
                
                slugStatus.className = 'd-flex align-items-center gap-2 ' + colorClass;
                slugStatus.innerHTML = icon + ' <span>' + message + '</span>' + extraHtml;
                
                // Attach event listener for suggestion button
                const useSuggestedBtn = document.getElementById('useSuggested');
                if (useSuggestedBtn) {
                    useSuggestedBtn.addEventListener('click', function() {
                        const suggested = this.getAttribute('data-slug');
                        slugInput.value = suggested;
                        isSlugValid = true;
                        setButtonState(true);
                        showFeedback('available', `Your store link: <strong>${suggested}.{{ config('app.main_domain') }}</strong>`);
                    });
                }
            }

            function checkSlug(storeName) {
                // Cancel any pending request
                if (currentAbortController) {
                    currentAbortController.abort();
                }
                
                // Convert to slug format for preview
                const previewSlug = storeName.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
                
                if (previewSlug.length < 2) {
                    slugFeedback.style.display = 'none';
                    slugInput.value = '';
                    isSlugValid = false;
                    setButtonState(false);
                    return;
                }
                
                showFeedback('checking', 'Checking store link availability...');
                
                currentAbortController = new AbortController();
                
                fetch(checkSlugUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ slug: storeName }),
                    signal: currentAbortController.signal
                })
                .then(response => response.json())
                .then(data => {
                    if (data.available) {
                        slugInput.value = data.slug;
                        isSlugValid = true;
                        setButtonState(true);
                        showFeedback('available', `Your store link: <strong>${data.slug}.{{ config('app.main_domain') }}</strong>`);
                    } else {
                        slugInput.value = '';
                        isSlugValid = false;
                        setButtonState(false);
                        showFeedback('taken', data.message, data.suggested);
                    }
                })
                .catch(error => {
                    if (error.name === 'AbortError') return;
                    console.error('Slug check error:', error);
                    slugInput.value = '';
                    isSlugValid = false;
                    setButtonState(false);
                    showFeedback('error', 'Unable to check availability. Please try again.');
                });
            }

            function checkSubmitButton() {
                // Only check slug validation now (bank moved to separate page)
                setButtonState(isSlugValid);
            }
            
            // Simplified button state function  
            const originalSetButtonState = setButtonState;
            setButtonState = function(enabled) {
                submitBtn.disabled = !enabled;
                submitBtn.classList.toggle('btn-dark', enabled);
                submitBtn.classList.toggle('btn-secondary', !enabled);
            };

            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    const value = this.value.trim();
                    
                    if (value.length < 2) {
                        slugFeedback.style.display = 'none';
                        slugInput.value = '';
                        isSlugValid = false;
                        checkSubmitButton(); // Changed from setButtonState(false)
                        return;
                    }
                    
                    // Show checking state after brief delay
                    debounceTimer = setTimeout(() => {
                        checkSlug(value);
                    }, 500);
                });
                
                // Check on page load if there's a value
                if (nameInput.value.trim().length >= 2) {
                    checkSlug(nameInput.value.trim());
                }
            }
        });
    </script>
@endsection
