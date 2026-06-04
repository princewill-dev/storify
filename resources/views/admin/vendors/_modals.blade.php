{{-- Shared modals for vendor show page --}}

<!-- Delete Business Modal -->
<div class="modal fade" id="deleteVendorModal" tabindex="-1" aria-labelledby="deleteVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteVendorLabel">Delete Business</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="deleteVendorForm" method="POST" action="#">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <p class="mb-1">You're about to delete this business:</p>
          <input type="text" id="deleteVendorName" class="form-control" disabled>
          <p class="mt-3 mb-0 text-danger small">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Business Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" aria-labelledby="editVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editVendorLabel">Edit Business</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editVendorForm" action="#" method="POST">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" id="editVendorName" class="form-control" required>
          </div>
          <div class="mb-3" style="display: none;">
            <label class="form-label">Slug</label>
            <input type="text" name="slug" id="editVendorSlug" class="form-control" placeholder="auto-generated from name if left blank">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="editVendorEmail" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="editVendorPhone" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="editVendorStatus" class="form-select">
              <option value="active">active</option>
              <option value="inactive">inactive</option>
              <option value="suspended">suspended</option>
              <option value="deleted">deleted</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Suspend Business Modal -->
<div class="modal fade" id="suspendVendorModal" tabindex="-1" aria-labelledby="suspendVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="suspendVendorLabel">Suspend Business</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="suspendVendorForm" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Business</label>
            <input type="text" class="form-control" id="suspendVendorName" disabled>
          </div>
          <div class="mb-3">
            <label for="suspendReason" class="form-label">Reason</label>
            <textarea class="form-control" id="suspendReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Suspend</button>
        </div>
      </form>
    </div>
  </div>
</div>
