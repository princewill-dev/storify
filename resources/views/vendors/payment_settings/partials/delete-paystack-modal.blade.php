<!-- Delete Paystack Keys Modal -->
<div class="modal fade" id="deletePaystackModal" tabindex="-1" aria-labelledby="deletePaystackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="deletePaystackModalLabel">
                    <i class="fi fi-rr-trash text-danger me-2"></i>Delete Paystack Keys
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fi fi-rr-exclamation me-2"></i>
                        <strong>Warning:</strong> Deleting these API keys will disable online payments for this store.
                    </div>
                    <p class="mb-0">
                        Delete Paystack keys for: <strong class="gateway-name"></strong>
                    </p>
                    <small class="text-muted">This action cannot be undone.</small>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fi fi-rr-trash me-1"></i>Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
