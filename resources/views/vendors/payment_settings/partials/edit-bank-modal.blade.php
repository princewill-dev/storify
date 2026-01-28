<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editBankModal" tabindex="-1" aria-labelledby="editBankModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBankModalLabel">
                    <i class="fi fi-rr-edit me-2"></i>Edit Bank Account
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_store_id" class="form-label">Store <span class="text-danger">*</span></label>
                        <select class="form-select" name="store_id" id="edit_store_id" required>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_bank_code" class="form-label">Bank <span class="text-danger">*</span></label>
                        <select class="form-select" name="bank_code" id="edit_bank_code" required>
                            @foreach($banks as $bank)
                                <option value="{{ $bank['code'] }}" data-name="{{ $bank['name'] }}">{{ $bank['name'] }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="bank_name" id="edit_bank_name">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_account_number" class="form-label">Account Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="account_number" id="edit_account_number" 
                               maxlength="10" pattern="[0-9]{10}" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_account_name" class="form-label">Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="account_name" id="edit_account_name" required>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_primary" id="edit_is_primary" value="1">
                        <label class="form-check-label" for="edit_is_primary">
                            Set as primary bank account
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fi fi-rr-check me-1"></i>Update Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBankSelect = document.getElementById('edit_bank_code');
    const editBankNameInput = document.getElementById('edit_bank_name');
    
    editBankSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        editBankNameInput.value = selected.dataset.name || '';
    });
});
</script>
@endpush
