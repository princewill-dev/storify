<div id="editFeatureModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editFeatureModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Edit feature</h5>
        <button onclick="closeModal('editFeatureModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="POST" id="editFeatureForm" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Display order</label>
          <input type="number" name="order" id="edit-order" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" min="0">
          <p class="mt-1 text-xs text-slate-400">Lower numbers appear first.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
          <input type="text" name="title" id="edit-title" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea name="description" id="edit-description" rows="3" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Icon</label>
          <input type="file" name="icon" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".jpg,.jpeg,.png,.webp">
          <p class="mt-1 text-xs text-slate-400">Uploading a new icon will replace the previous one.</p>
          <div class="mt-2">
            <small class="block text-xs text-slate-400">Current icon</small>
            <div id="edit-icon-preview" class="mt-1 w-16 h-16 rounded-xl border border-slate-200 flex items-center justify-center bg-cover bg-center"></div>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('editFeatureModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
