<div class="modal fade" id="editFeatureModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit feature</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="editFeatureForm" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Display order</label>
            <input type="number" name="order" id="edit-order" class="form-control" min="0">
            <div class="form-text">Lower numbers appear first.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" id="edit-title" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="edit-description" class="form-control" rows="3" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Icon</label>
            <input type="file" name="icon" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">Uploading a new icon will replace the previous one.</div>
            <div class="mt-2">
              <small class="text-muted d-block">Current icon</small>
              <div id="edit-icon-preview" style="width: 64px; height: 64px; border-radius: 12px; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center; background-size:cover; background-position:center;"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
