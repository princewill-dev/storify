{{-- Shared modals for vendor show page --}}

{{-- Delete Business Modal --}}
<div id="deleteVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Delete Business</h5>
            <form id="deleteVendorForm" method="POST" action="#">
                @csrf
                @method('DELETE')
                <p class="text-sm text-slate-600 mb-3">You're about to delete this business:</p>
                <input type="text" id="deleteVendorName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" disabled>
                <p class="mt-3 text-xs text-red-500">This action cannot be undone.</p>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="closeModal('deleteVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Business Modal --}}
<div id="editVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Edit Business</h5>
            <form id="editVendorForm" action="#" method="POST" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input type="text" name="name" id="editVendorName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                </div>
                <div class="hidden">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Slug</label>
                    <input type="text" name="slug" id="editVendorSlug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg" placeholder="auto-generated from name if left blank">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" id="editVendorEmail" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Phone</label>
                    <input type="text" name="phone" id="editVendorPhone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" id="editVendorStatus" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                        <option value="suspended">suspended</option>
                        <option value="deleted">deleted</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('editVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Suspend Business Modal --}}
<div id="suspendVendorModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('suspendVendorModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Suspend Business</h5>
            <form id="suspendVendorForm" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Business</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="suspendVendorName" disabled>
                </div>
                <div>
                    <label for="suspendReason" class="block text-sm font-medium text-slate-700 mb-1">Reason</label>
                    <textarea class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" id="suspendReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('suspendVendorModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600">Suspend</button>
                </div>
            </form>
        </div>
    </div>
</div>
