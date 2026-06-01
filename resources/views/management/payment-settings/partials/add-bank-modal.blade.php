<!-- Add Bank Account Modal -->
<div class="modal fade" id="addBankModal" tabindex="-1" aria-labelledby="addBankModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBankModalLabel">
                    <i class="fi fi-rr-bank me-2"></i>Add Bank Account
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('management.payment-settings.bank-accounts.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_store_id" class="form-label">Store <span class="text-danger">*</span></label>
                        <select class="form-select" name="store_id" id="add_store_id" required>
                            <option value="">Select Store</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_bank_code" class="form-label">Bank <span class="text-danger">*</span></label>
                        <select class="form-select" name="bank_code" id="add_bank_code" required>
                            <option value="">Select Bank</option>
                            @foreach($banks as $bank)
                                <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="bank_name" id="add_bank_name">
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_account_number" class="form-label">Account Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="account_number" id="add_account_number" 
                                   maxlength="10" pattern="[0-9]{10}" required placeholder="Enter 10-digit account number">
                            <button type="button" class="btn btn-outline-secondary" id="verifyBankBtn">
                                <span class="spinner-border spinner-border-sm d-none" id="verifySpinner"></span>
                                Verify
                            </button>
                        </div>
                        <div id="verifyFeedback" class="form-text"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_account_name" class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="account_name" id="add_account_name" required readonly>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addBankSubmit" disabled>
                        <i class="fi fi-rr-check me-1"></i>Add Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const bankSelect = document.getElementById('add_bank_code');
    const bankNameInput = document.getElementById('add_bank_name');
    const accountNumberInput = document.getElementById('add_account_number');
    const accountNameInput = document.getElementById('add_account_name');
    const verifyBtn = document.getElementById('verifyBankBtn');
    const verifySpinner = document.getElementById('verifySpinner');
    const verifyFeedback = document.getElementById('verifyFeedback');
    const submitBtn = document.getElementById('addBankSubmit');
    
    // Update hidden bank name when bank is selected
    bankSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        bankNameInput.value = selected.dataset.name || '';
        resetVerification();
    });
    
    // Reset when account number changes
    accountNumberInput.addEventListener('input', function() {
        resetVerification();
    });
    
    function resetVerification() {
        accountNameInput.value = '';
        submitBtn.disabled = true;
        verifyFeedback.textContent = '';
        verifyFeedback.className = 'form-text';
    }
    
    // Verify bank account
    verifyBtn.addEventListener('click', async function() {
        const bankCode = bankSelect.value;
        const accountNumber = accountNumberInput.value;
        
        if (!bankCode || accountNumber.length !== 10) {
            verifyFeedback.textContent = 'Please select a bank and enter a 10-digit account number.';
            verifyFeedback.className = 'form-text text-danger';
            return;
        }
        
        verifyBtn.disabled = true;
        verifySpinner.classList.remove('d-none');
        verifyFeedback.textContent = 'Verifying...';
        verifyFeedback.className = 'form-text text-muted';
        
        try {
            const response = await fetch('{{ route("management.payment-settings.verify-bank") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    bank_code: bankCode,
                    account_number: accountNumber
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.account_name) {
                accountNameInput.value = data.account_name;
                submitBtn.disabled = false;
                verifyFeedback.textContent = 'Account verified successfully!';
                verifyFeedback.className = 'form-text text-success';
            } else {
                verifyFeedback.textContent = data.message || 'Could not verify account. Please check details.';
                verifyFeedback.className = 'form-text text-danger';
            }
        } catch (error) {
            verifyFeedback.textContent = 'Verification failed. Please try again.';
            verifyFeedback.className = 'form-text text-danger';
        } finally {
            verifyBtn.disabled = false;
            verifySpinner.classList.add('d-none');
        }
    });
});
</script>
@endpush
