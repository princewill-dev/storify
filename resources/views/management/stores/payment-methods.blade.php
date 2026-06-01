@extends('management.layout')

@section('title', 'Set Up Payment Methods')

@push('styles')
<style>
    .auth-card { width: min(680px, 100%); }
</style>
@endpush
@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="mb-2">Set Up Payment Methods</h2>
        <p class="text-muted mb-4">Choose how you want to receive payments from your customers.</p>

        <!-- @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>{{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif -->

        @if($configuredMethod)
            {{-- Success State - Method Already Configured --}}
            <div class="alert alert-success border-success">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-check-circle fa-2x text-success me-3"></i>
                    <div>
                        <h5 class="mb-0">Payment Method Configured!</h5>
                        <p class="mb-0 small">You're all set to receive payments</p>
                    </div>
                </div>
            </div>

            <div class="card bg-light border-primary">
                <div class="card-body">
                    @if($configuredMethod === 'bank')
                        <h5 class="mb-3"><i class="fas fa-university me-2 text-primary"></i> Manual Payments (Bank Transfer)</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1 small text-muted">Bank Name</p>
                                <p class="fw-semibold">{{ $configuredData->bank_name }}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1 small text-muted">Account Number</p>
                                <p class="fw-semibold">{{ $configuredData->masked_account_number }}</p>
                            </div>
                            <div class="col-12">
                                <p class="mb-1 small text-muted">Account Name</p>
                                <p class="fw-semibold">{{ $configuredData->account_name }}</p>
                            </div>
                        </div>
                    @else
                        <h5 class="mb-3"><i class="fab fa-paypal me-2 text-primary"></i> Automatic Payments (Paystack)</h5>
                        <div class="row">
                            <div class="col-12">
                                <p class="mb-1 small text-muted">Public Key</p>
                                <p class="fw-semibold font-monospace">{{ $configuredData->masked_public_key }}</p>
                            </div>
                            <div class="col-12">
                                <p class="mb-1 small text-muted">Status</p>
                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Active</span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <p class="text-muted small mt-3"><i class="fas fa-info-circle me-1"></i> You can update your payment settings later from your store dashboard.</p>

        @else
            {{-- Initial State - Select Payment Method --}}
            <div class="row g-4 mb-4">
                {{-- Manual Payments Option --}}
                <div class="col-md-6">
                    <div class="card h-100 payment-option-card" data-bs-toggle="modal" data-bs-target="#bankModal" style="cursor: pointer;">
                        <div class="card-body text-center p-4">
                            <div class="mb-3">
                                <i class="fas fa-university fa-3x text-primary"></i>
                            </div>
                            <h5 class="fw-semibold mb-2">Manual Payments</h5>
                            <p class="text-muted small mb-3">Receive payments via bank transfer. You'll manually confirm each payment.</p>
                            <ul class="list-unstyled text-start small text-muted">
                                <li><i class="fas fa-check text-success me-2"></i> No transaction fees</li>
                                <li><i class="fas fa-check text-success me-2"></i> Direct to your account</li>
                                <li><i class="fas fa-check text-success me-2"></i> Manual confirmation required</li>
                            </ul>
                            <button type="button" class="btn btn-outline-primary mt-2">
                                <i class="fas fa-plus me-2"></i>Add Bank Account
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Paystack Option --}}
                <div class="col-md-6">
                    <div class="card h-100 payment-option-card" data-bs-toggle="modal" data-bs-target="#paystackModal" style="cursor: pointer;">
                        <div class="card-body text-center p-4">
                            <div class="mb-3">
                                <i class="fab fa-paypal fa-3x text-success"></i>
                            </div>
                            <h5 class="fw-semibold mb-2">Automatic Payments</h5>
                            <p class="text-muted small mb-3">Instant payment confirmation via Paystack. Fastest checkout experience.</p>
                            <ul class="list-unstyled text-start small text-muted">
                                <li><i class="fas fa-check text-success me-2"></i> Instant confirmation</li>
                                <li><i class="fas fa-check text-success me-2"></i> Multiple payment methods</li>
                                <li><i class="fas fa-check text-success me-2"></i> Transaction fees apply</li>
                            </ul>
                            <button type="button" class="btn btn-outline-success mt-2">
                                <i class="fas fa-plus me-2"></i>Configure Paystack
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <hr>

        <div class="d-flex justify-content-between align-items-center">
            <form action="{{ route('management.payment-methods.skip') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-link text-decoration-none" style="color: #000000">
                    Do this later
                </button>
            </form>

            @if($configuredMethod)
                <a href="{{ route('management.delivery-routes.form') }}" class="btn btn-primary">
                    <i class="fas fa-arrow-right me-2"></i>Proceed to Delivery Routes
                </a>
            @endif
        </div>
    </div>
</div>

{{-- Bank Account Modal --}}
<div class="modal fade" id="bankModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-university me-2"></i>Add Bank Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('management.payment-methods.bank') }}" method="POST" id="bankForm">
                @csrf
                <div class="modal-body">
                    <p class="text-muted small mb-3">Enter your bank details to receive payments. The account name must match your KYC documents.</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bank Name <span class="text-danger">*</span></label>
                        <select name="bank_code" class="form-select bank-selector" required>
                            <option value="">Select Bank</option>
                        </select>
                        <input type="hidden" name="bank_name" class="bank-name-hidden">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Account Number <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control account-number-input" maxlength="10" pattern="[0-9]{10}" placeholder="0123456789" required>
                        <div class="bank-validation-feedback mt-1 small"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Account Name</label>
                        <input type="text" name="account_name" class="form-control" readonly required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="bankSubmit" disabled>
                        <i class="fas fa-save me-2"></i>Save Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Paystack Modal --}}
<div class="modal fade" id="paystackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fab fa-paypal me-2"></i>Configure Paystack</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('management.payment-methods.paystack') }}" method="POST" id="paystackForm">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        Get your Paystack API keys from your <a href="https://dashboard.paystack.com/#/settings/developers" target="_blank">Paystack Dashboard</a>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Public Key <span class="text-danger">*</span></label>
                        <input type="text" name="public_key" class="form-control font-monospace" placeholder="pk_live_..." required pattern="pk_[a-zA-Z0-9_]+">
                        <small class="form-text text-muted">Starts with pk_</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Secret Key <span class="text-danger">*</span></label>
                        <input type="password" name="secret_key" class="form-control font-monospace" placeholder="sk_live_..." required pattern="sk_[a-zA-Z0-9_]+">
                        <small class="form-text text-muted">Starts with sk_ (will be encrypted)</small>
                    </div>

                    <div class="alert alert-warning small">
                        <i class="fas fa-lock me-2"></i>
                        <strong>Secure:</strong> Your API keys will be encrypted before storage and only decrypted when processing payments.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .payment-option-card {
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    .payment-option-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
        border-color: var(--bs-primary);
    }
</style>

<script>
    // Bank loading and verification logic (reuse from store show page)
    document.addEventListener('DOMContentLoaded', function() {
        let banksCache = null;
        
        function loadBanksIntoSelector(selector) {
            console.log('[Payment Methods] Loading banks into selector');
            
            if (!selector) {
                console.error('[Payment Methods] No selector provided');
                return;
            }
            
            if (banksCache) {
                console.log('[Payment Methods] Using cached banks:', banksCache.length);
                selector.innerHTML = '<option value="">Select Bank</option>';
                banksCache.forEach(bank => {
                    const option = document.createElement('option');
                    option.value = bank.code;
                    option.textContent = bank.name;
                    selector.appendChild(option);
                });
            } else {
                console.log('[Payment Methods] Fetching banks from API');
                selector.innerHTML = '<option value="">Loading banks...</option>';
                
                fetch("{{ route('management.store.get-banks') }}")
                    .then(response => response.json())
                    .then(data => {
                        console.log('[Payment Methods] Banks loaded:', data);
                        selector.innerHTML = '<option value="">Select Bank</option>';
                        const banks = data.data || [];
                        if (banks && banks.length > 0) {
                            banksCache = banks;
                            banks.forEach(bank => {
                                const option = document.createElement('option');
                                option.value = bank.code;
                                option.textContent = bank.name;
                                selector.appendChild(option);
                            });
                        }
                    })
                    .catch(err => {
                        console.error('[Payment Methods] Failed to load banks:', err);
                        selector.innerHTML = '<option value="">Failed to load banks</option>';
                    });
            }
        }

        // Load banks when modal is shown
        var bankModal = document.getElementById('bankModal');
        if (bankModal) {
            bankModal.addEventListener('show.bs.modal', function () {
                const selector = this.querySelector('.bank-selector');
                loadBanksIntoSelector(selector);
            });
        }

        // Handle bank name synchronization
        document.querySelectorAll('.bank-selector').forEach(select => {
            select.addEventListener('change', function() {
                const nameHidden = this.closest('form').querySelector('.bank-name-hidden');
                if (nameHidden) {
                    nameHidden.value = this.options[this.selectedIndex].text;
                }
            });
        });

        // Auto-verify on 10-digit account number entry
        document.querySelectorAll('.account-number-input').forEach(input => {
            input.addEventListener('input', function() {
                const form = this.closest('form');
                const accountNumber = this.value;
                const bankCode = form.querySelector('select[name="bank_code"]').value;
                const feedback = form.querySelector('.bank-validation-feedback');
                const accountNameInput = form.querySelector('input[name="account_name"]');
                const submitBtn = form.querySelector('button[type="submit"]');

                if (accountNumber.length < 10) {
                    accountNameInput.value = '';
                    feedback.innerHTML = '';
                    submitBtn.disabled = true;
                    return;
                }

                if (accountNumber.length === 10) {
                    if (!bankCode) {
                        feedback.innerHTML = '<span class="text-warning"><i class="fi fi-rr-exclamation"></i> Please select a bank first</span>';
                        submitBtn.disabled = true;
                        return;
                    }

                    feedback.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Verifying...</span>';
                    accountNameInput.value = '';
                    submitBtn.disabled = true;

                    fetch("{{ route('management.store.validate-bank') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ account_number: accountNumber, bank_code: bankCode })
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('[Payment Methods] Verification response:', data);
                        if (data.success && data.data && data.data.account_name) {
                            accountNameInput.value = data.data.account_name;
                            feedback.innerHTML = '<span class="text-success fw-bold">✓ Account verified successfully</span>';
                            submitBtn.disabled = false;
                        } else {
                            accountNameInput.value = '';
                            feedback.innerHTML = '<span class="text-danger">✗ ' + (data.message || 'Verification failed') + '</span>';
                            submitBtn.disabled = true;
                        }
                    })
                    .catch(err => {
                        console.error('[Payment Methods] Verification error:', err);
                        accountNameInput.value = '';
                        feedback.innerHTML = '<span class="text-danger">✗ Error during verification</span>';
                        submitBtn.disabled = true;
                    });
                }
            });
        });
    });
</script>
@endsection
