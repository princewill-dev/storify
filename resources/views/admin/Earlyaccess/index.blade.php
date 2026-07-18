@extends('admin.layout')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Early Access Codes</h2>
    <button type="button" onclick="openModal('createModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-plus"></i> Create New Code
    </button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Code</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Description</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Usage Count</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Created At</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($passes as $pass)
            <tr>
                <td class="py-3 px-4">
                    <span class="font-semibold text-slate-900">{{ $pass->code }}</span>
                </td>
                <td class="py-3 px-4 text-slate-700">{{ $pass->description ?? '-' }}</td>
                <td class="py-3 px-4">
                    @if($pass->is_active)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium">Active</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-red-50 text-red-700 px-2.5 py-0.5 text-xs font-medium">Inactive</span>
                    @endif
                </td>
                <td class="py-3 px-4">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-slate-900">{{ $pass->usages_count }} <span class="font-normal text-slate-400">/ {{ $pass->max_uses ?? '∞' }}</span></span>
                        <a href="{{ route('admin.early-access.show', $pass) }}" class="text-xs text-slate-500 hover:text-slate-700">View Details</a>
                    </div>
                </td>
                <td class="py-3 px-4 text-slate-700">{{ $pass->created_at?->format('d M Y H:i') }}</td>
                <td class="py-3 px-4 text-right">
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                            <i class="fi fi-rr-menu-dots-vertical"></i>
                        </button>
                        <div x-show="open" @click.outside="open = false" class="absolute right-0 z-10 mt-1 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 text-sm" x-cloak>
                            <a href="{{ route('admin.early-access.show', $pass) }}" class="flex items-center gap-2 px-4 py-2 text-slate-700 hover:bg-slate-50">
                                <i class="fi fi-rr-eye text-slate-400"></i> View Usages
                            </a>
                            <a href="#"
                                onclick="openEditModal('{{ $pass->id }}','{{ $pass->code }}','{{ $pass->description }}','{{ $pass->max_uses }}')"
                                class="flex items-center gap-2 px-4 py-2 text-slate-700 hover:bg-slate-50">
                                <i class="fi fi-rr-edit text-slate-400"></i> Edit
                            </a>
                            <form action="{{ route('admin.early-access.toggle-status', $pass) }}" method="POST" class="block">
                                @csrf
                                <button type="submit" class="flex items-center gap-2 px-4 py-2 text-slate-700 hover:bg-slate-50 w-full text-left">
                                    @if($pass->is_active)
                                        <i class="fi fi-rr-ban text-amber-500"></i> Deactivate
                                    @else
                                        <i class="fi fi-rr-check text-emerald-500"></i> Activate
                                    @endif
                                </button>
                            </form>
                            <div class="border-t border-slate-100 my-1"></div>
                            <a href="#"
                                onclick="openDeleteModal('{{ $pass->id }}','{{ $pass->code }}')"
                                class="flex items-center gap-2 px-4 py-2 text-red-600 hover:bg-red-50">
                                <i class="fi fi-rr-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="py-12 text-center">
                    <div class="flex flex-col items-center">
                        <i class="fi fi-rr-ticket text-3xl text-slate-300 mb-3"></i>
                        <h5 class="text-slate-500 font-medium mb-3">No early access codes found</h5>
                        <button type="button" onclick="openModal('createModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create First Code</button>
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $passes->links() }}
    </div>
</div>

{{-- Create Modal --}}
<div class="relative z-50 hidden" id="createModal" aria-labelledby="createModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="createModal-title">Create Early Access Code</h3>
                <button type="button" onclick="closeModal('createModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form action="{{ route('admin.early-access.store') }}" method="POST">
                @csrf
                <div class="p-5 space-y-4">
                    <div>
                        <label for="code" class="block text-sm font-medium text-slate-700 mb-1">Code <span class="text-red-500">*</span></label>
                        <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" id="code" name="code" required minlength="3" placeholder="e.g. EARLYBIRD2025">
                        <p class="text-xs text-slate-400 mt-1">Codes are handled in uppercase.</p>
                    </div>
                    <div>
                        <label for="max_uses" class="block text-sm font-medium text-slate-700 mb-1">Max Uses (Optional)</label>
                        <input type="number" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" id="max_uses" name="max_uses" min="1" placeholder="Leave blank for unlimited">
                        <p class="text-xs text-slate-400 mt-1">Code passes will auto-deactivate when limit is reached.</p>
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                        <textarea class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" id="description" name="description" rows="3" placeholder="Internal note about this code..."></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('createModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Modal --}}
<div class="relative z-50 hidden" id="editModal" aria-labelledby="editModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="editModal-title">Edit Early Access Code</h3>
                <button type="button" onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form id="editForm" action="" method="POST">
                @csrf
                @method('PUT')
                <div class="p-5 space-y-4">
                    <div>
                        <label for="edit_code" class="block text-sm font-medium text-slate-700 mb-1">Code</label>
                        <input type="text" class="w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500" id="edit_code" disabled>
                    </div>
                    <div>
                        <label for="edit_max_uses" class="block text-sm font-medium text-slate-700 mb-1">Max Uses</label>
                        <input type="number" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" id="edit_max_uses" name="max_uses" min="1" placeholder="Unlimited">
                    </div>
                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                        <textarea class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('editModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete Modal --}}
<div class="relative z-50 hidden" id="deleteModal" aria-labelledby="deleteModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-sm">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="deleteModal-title">Delete Code</h3>
                <button type="button" onclick="closeModal('deleteModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <div class="p-5">
                <p class="text-sm text-slate-600">Are you sure you want to delete this code? This action cannot be undone.</p>
            </div>
            <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                <form id="deleteForm" action="" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="button" onclick="closeModal('deleteModal')" class="inline-flex items-center gap-1.5 px-4 py-2 mr-3 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openEditModal(id, code, description, maxUses) {
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_max_uses').value = maxUses;
    document.getElementById('editForm').action = '/superadmin/early-access/' + code;
    openModal('editModal');
}

function openDeleteModal(id, code) {
    document.getElementById('deleteForm').action = '/superadmin/early-access/' + code;
    openModal('deleteModal');
}
</script>
@endpush
@endsection
