@extends('vendors.auth.layout')

@section('subtitle', 'Create your store')

@section('content')
    <div class="mb-4 text-center">
        <h3 class="fw-semibold mb-1">Almost there! Let’s set up your store</h3>
        <p class="text-muted mb-0">Complete the details below so we can prepare your storefront while our team reviews your KYC.</p>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form action="{{ route('vendor.kyc.store.submit', ['vendor' => $vendor]) }}" method="POST" enctype="multipart/form-data" class="vstack gap-4">
                @csrf

                <div class="row g-4">
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
                        <label for="support_email" class="form-label fw-semibold">Support email</label>
                        <input type="email" id="support_email" name="support_email"
                               class="form-control form-control-lg @error('support_email') is-invalid @enderror"
                               value="{{ old('support_email') }}" placeholder="support@yourbrand.com">
                        @error('support_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="support_phone" class="form-label fw-semibold">Support phone</label>
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

                <div class="row g-4">
                    <div class="col-lg-6">
                        <label for="instagram_url" class="form-label fw-semibold">Instagram</label>
                        <input type="url" id="instagram_url" name="instagram_url"
                               class="form-control form-control-lg @error('instagram_url') is-invalid @enderror"
                               value="{{ old('instagram_url') }}" placeholder="https://www.instagram.com/yourhandle">
                        @error('instagram_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="facebook_url" class="form-label fw-semibold">Facebook</label>
                        <input type="url" id="facebook_url" name="facebook_url"
                               class="form-control form-control-lg @error('facebook_url') is-invalid @enderror"
                               value="{{ old('facebook_url') }}" placeholder="https://www.facebook.com/yourpage">
                        @error('facebook_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <label for="twitter_url" class="form-label fw-semibold">Twitter / X</label>
                        <input type="url" id="twitter_url" name="twitter_url"
                               class="form-control form-control-lg @error('twitter_url') is-invalid @enderror"
                               value="{{ old('twitter_url') }}" placeholder="https://twitter.com/yourhandle">
                        @error('twitter_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="tiktok_url" class="form-label fw-semibold">TikTok</label>
                        <input type="url" id="tiktok_url" name="tiktok_url"
                               class="form-control form-control-lg @error('tiktok_url') is-invalid @enderror"
                               value="{{ old('tiktok_url') }}" placeholder="https://www.tiktok.com/@yourhandle">
                        @error('tiktok_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="logo" class="form-label fw-semibold">Store logo</label>
                    <div class="border rounded-3 p-4 d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3">
                        <div class="border rounded-3" style="width: 160px; height: 90px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#f4f4f5;">
                            <img id="logoPreview" src="#" alt="Logo preview" style="max-width:100%; max-height:100%; display:none;">
                        </div>
                        <div class="flex-grow-1">
                            <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp"
                                   class="form-control form-control-lg @error('logo') is-invalid @enderror">
                            <small class="text-muted d-block mt-2">PNG, JPG, or WEBP. Max 2MB.</small>
                            @error('logo')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="card bg-light border-0">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Bank Account Details</h5>
                        <p class="text-muted small mb-3">Add your bank account to receive payouts. The account name must match your KYC documents.</p>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="bank_code" class="form-label fw-semibold">Select Bank</label>
                                <select id="bank_code" name="bank_code" class="form-select form-select-lg @error('bank_code') is-invalid @enderror" required>
                                    <option value="" disabled selected>Loading banks...</option>
                                </select>
                                <input type="hidden" id="bank_name" name="bank_name" value="{{ old('bank_name') }}">
                                @error('bank_code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="account_number" class="form-label fw-semibold">Account Number</label>
                                <div class="position-relative">
                                    <input type="text" id="account_number" name="account_number" 
                                           class="form-control form-control-lg @error('account_number') is-invalid @enderror"
                                           value="{{ old('account_number') }}" placeholder="0123456789" maxlength="10" required>
                                    <div id="accountCheckingSpinner" class="position-absolute top-50 end-0 translate-middle-y me-3" style="display: none;">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                </div>
                                <div id="accountNameFeedback" class="form-text mt-1 fw-bold text-success" style="display: none;"></div>
                                <input type="hidden" id="account_name" name="account_name" value="{{ old('account_name') }}">
                                @error('account_number')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                                @error('account_name')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
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
            // Logo preview
            const logoInput = document.getElementById('logo');
            const preview = document.getElementById('logoPreview');
            if (logoInput && preview) {
                logoInput.addEventListener('change', function (event) {
                    const file = event.target.files?.[0];
                    if (!file) {
                        preview.style.display = 'none';
                        preview.src = '#';
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        preview.src = e.target?.result ?? '#';
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                });
            }

            // Slug availability checker
            const nameInput = document.getElementById('name');
            const slugInput = document.getElementById('slug');
            const slugFeedback = document.getElementById('slugFeedback');
            const slugStatus = document.getElementById('slugStatus');
            const submitBtn = document.getElementById('submitBtn');
            const checkSlugUrl = '{{ route("vendor.kyc.store.check-slug", ["vendor" => $vendor]) }}';
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

            // Bank validation
            const bankSelect = document.getElementById('bank_code');
            const bankNameInput = document.getElementById('bank_name');
            const accountNumberInput = document.getElementById('account_number');
            const accountNameInput = document.getElementById('account_name');
            const accountNameFeedback = document.getElementById('accountNameFeedback');
            const accountCheckingSpinner = document.getElementById('accountCheckingSpinner');
            
            const getBanksUrl = '{{ route("vendor.kyc.store.get-banks", ["vendor" => $vendor]) }}';
            const validateBankUrl = '{{ route("vendor.kyc.store.validate-bank", ["vendor" => $vendor]) }}';
            
            let isBankVerified = false;

            // Load banks
            fetch(getBanksUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        bankSelect.innerHTML = '<option value="" disabled selected>Select a bank</option>';
                        // Sort banks alphabetically
                        data.data.sort((a, b) => a.name.localeCompare(b.name));
                        
                        data.data.forEach(bank => {
                            const option = document.createElement('option');
                            option.value = bank.code;
                            option.text = bank.name;
                            option.dataset.name = bank.name;
                            if (bank.code === '{{ old("bank_code") }}') {
                                option.selected = true;
                            }
                            bankSelect.appendChild(option);
                        });
                        
                        // Trigger validation if we have old values
                        if (bankSelect.value && accountNumberInput.value.length === 10) {
                            validateBankAccount();
                        }
                    } else {
                        bankSelect.innerHTML = '<option value="" disabled selected>Error loading banks</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading banks:', error);
                    bankSelect.innerHTML = '<option value="" disabled selected>Error loading banks</option>';
                });

            bankSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                bankNameInput.value = selectedOption.dataset.name;
                validateBankAccount();
            });

            accountNumberInput.addEventListener('input', function() {
                // Only allow numbers
                this.value = this.value.replace(/[^0-9]/g, '');
                
                if (this.value.length === 10) {
                    validateBankAccount();
                } else {
                    isBankVerified = false;
                    accountNameFeedback.style.display = 'none';
                    accountNameInput.value = '';
                    checkSubmitButton();
                }
            });

            function validateBankAccount() {
                const bankCode = bankSelect.value;
                const accountNumber = accountNumberInput.value;

                if (!bankCode || accountNumber.length !== 10) {
                    return;
                }

                accountCheckingSpinner.style.display = 'block';
                accountNameFeedback.innerHTML = '';
                accountNameFeedback.style.display = 'none';
                isBankVerified = false;
                checkSubmitButton();

                fetch(validateBankUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        bank_code: bankCode, 
                        account_number: accountNumber 
                    })
                })
                .then(response => response.json())
                .then(data => {
                    accountCheckingSpinner.style.display = 'none';
                    if (data.success) {
                        isBankVerified = true;
                        accountNameInput.value = data.data.account_name;
                        accountNameFeedback.className = 'form-text mt-1 fw-bold text-success';
                        accountNameFeedback.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> ${data.data.account_name}`;
                        accountNameFeedback.style.display = 'block';
                    } else {
                        isBankVerified = false;
                        accountNameInput.value = '';
                        accountNameFeedback.className = 'form-text mt-1 fw-bold text-danger';
                        accountNameFeedback.innerHTML = `<i class="bi bi-x-circle-fill me-1"></i> ${data.message}`;
                        accountNameFeedback.style.display = 'block';
                    }
                    checkSubmitButton();
                })
                .catch(error => {
                    accountCheckingSpinner.style.display = 'none';
                    console.error('Error validating bank:', error);
                    isBankVerified = false;
                    accountNameFeedback.className = 'form-text mt-1 fw-bold text-danger';
                    accountNameFeedback.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Validation failed';
                    accountNameFeedback.style.display = 'block';
                    checkSubmitButton();
                });
            }

            function checkSubmitButton() {
                // Modified setButtonState logic to include bank verification
                setButtonState(isSlugValid && isBankVerified);
            }
            
            // Override the original setButtonState to include bank check
            const originalSetButtonState = setButtonState;
            setButtonState = function(enabled) {
                // Update slug specific logic, but then re-evaluate everything
                // We shouldn't rely on the passed 'enabled' alone because it only knows about slug
                // But we need to use it to update isSlugValid
                if (document.activeElement === nameInput) {
                     // If we are editing name, enabled represents slug validity
                     // But we should track isSlugValid separately in the slug logic really.
                     // The original slug logic calls setButtonState(true/false) based on slug only.
                     // We should intercept this.
                }
                
                // Since the original slug logic calls setButtonState directly with true/false,
                // we need to be careful. The original code doesn't expose isSlugValid well enough to just use it here
                // without modifying the slug logic too. 
                // Wait, I declared isSlugValid at the top scope of this script block in previous edits.
                // So I can just use that variable since the slug logic updates it before calling setButtonState.
                
                // Let's redefine setButtonState to just update the button UI based on both flags
                const finalState = isSlugValid && isBankVerified;
                submitBtn.disabled = !finalState;
                submitBtn.classList.toggle('btn-dark', finalState);
                submitBtn.classList.toggle('btn-secondary', !finalState);
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
