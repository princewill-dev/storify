<div id="deleteFeatureModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteFeatureModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Delete feature</h5>
        <button onclick="closeModal('deleteFeatureModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <form method="POST" id="deleteFeatureForm" class="space-y-4">
        @csrf
        @method('DELETE')
        <p class="text-sm text-slate-600">Are you sure you want to delete <strong id="delete-title" class="text-slate-800"></strong>?</p>
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
          <button type="button" onclick="closeModal('deleteFeatureModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>
