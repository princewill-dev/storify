<!-- Edit Paystack Keys Modal -->
<div class="modal fade" id="editPaystackModal" tabindex="-1" aria-labelledby="editPaystackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPaystackModalLabel">
                    <i class="fi fi-rr-edit me-2"></i>Edit Paystack API Keys
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_paystack_store_id" class="form-label">Store <span class="text-danger">*</span></label>
                        <select class="form-select" name="store_id" id="edit_paystack_store_id" required>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_paystack_public_key" class="form-label">Public Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="public_key" id="edit_paystack_public_key" 
                               placeholder="pk_live_xxxx or pk_test_xxxx" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_paystack_secret_key" class="form-label">Secret Key <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="secret_key" id="edit_paystack_secret_key" 
                               placeholder="sk_live_xxxx or sk_test_xxxx" required>
                        <small class="text-muted">Your secret key is encrypted and stored securely.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fi fi-rr-check me-1"></i>Update Paystack Keys
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
