<!-- Add Paystack Keys Modal -->
<div class="modal fade" id="addPaystackModal" tabindex="-1" aria-labelledby="addPaystackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPaystackModalLabel">
                    <i class="fi fi-rr-key me-2"></i>Add Paystack API Keys
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('vendor.payment-settings.paystack-keys.store', ['vendor' => $vendor]) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fi fi-rr-info me-2"></i>
                        Get your API keys from your <a href="https://dashboard.paystack.com/#/settings/developers" target="_blank">Paystack Dashboard</a>.
                    </div>
                    
                    <div class="mb-3">
                        <label for="paystack_store_id" class="form-label">Store <span class="text-danger">*</span></label>
                        <select class="form-select" name="store_id" id="paystack_store_id" required>
                            <option value="">Select Store</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="paystack_public_key" class="form-label">Public Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="public_key" id="paystack_public_key" 
                               placeholder="pk_live_xxxx or pk_test_xxxx" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="paystack_secret_key" class="form-label">Secret Key <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="secret_key" id="paystack_secret_key" 
                               placeholder="sk_live_xxxx or sk_test_xxxx" required>
                        <small class="text-muted">Your secret key is encrypted and stored securely.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fi fi-rr-check me-1"></i>Add Paystack Keys
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
