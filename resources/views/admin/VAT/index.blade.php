@extends('admin.layout')
@section('subtitle','VAT Settings')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">VAT Settings</h2>
    <button type="button" onclick="openModal('vatCreateModal')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Add VAT</button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">ID</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Percentage</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Active</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Effective At</th>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Created</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($vats as $row)
                <tr>
                    <td class="py-3 px-4 text-slate-700">{{ $row->id }}</td>
                    <td class="py-3 px-4 text-slate-700">{{ number_format($row->percentage, 2) }}%</td>
                    <td class="py-3 px-4">
                        <span class="inline-flex items-center rounded-full {{ $row->active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2.5 py-0.5 text-xs font-medium">
                            {{ $row->active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="py-3 px-4 text-slate-700">{{ $row->effective_at ? $row->effective_at->format('Y-m-d H:i') : '—' }}</td>
                    <td class="py-3 px-4 text-slate-700">{{ $row->created_at->diffForHumans() }}</td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button"
                                onclick="openVatEdit('{{ $row->id }}','{{ $row->percentage }}','{{ $row->effective_at ? $row->effective_at->format('Y-m-d\\TH:i') : '' }}',{{ $row->active ? 1 : 0 }})"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-lg text-slate-600 hover:bg-slate-100">
                                <i class="fi fi-rr-edit"></i>
                            </button>
                            <button type="button"
                                onclick="openVatDisable('{{ $row->id }}')"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-lg text-amber-600 hover:bg-amber-50">
                                <i class="fi fi-rr-ban"></i>
                            </button>
                            <button type="button"
                                onclick="openVatDelete('{{ $row->id }}')"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-lg text-red-600 hover:bg-red-50">
                                <i class="fi fi-rr-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-12 text-center text-slate-400">No VAT records</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $vats->onEachSide(1)->links() }}
    </div>
</div>

{{-- Create VAT Modal --}}
<div class="relative z-50 hidden" id="vatCreateModal" aria-labelledby="vatCreateModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="vatCreateModal-title">Add VAT</h3>
                <button type="button" onclick="closeModal('vatCreateModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form action="{{ route('admin.vats.store') }}" method="POST">
                @csrf
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="percentage" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Effective At (optional)</label>
                        <input type="datetime-local" name="effective_at" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div class="flex items-center gap-2">
                        <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" value="1" id="vatCActive" name="active" checked>
                        <label class="text-sm text-slate-700" for="vatCActive">Active</label>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('vatCreateModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit VAT Modal --}}
<div class="relative z-50 hidden" id="vatEditModal" aria-labelledby="vatEditModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="vatEditModal-title">Edit VAT</h3>
                <button type="button" onclick="closeModal('vatEditModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form id="vatEditForm" action="#" method="POST">
                @csrf @method('PUT')
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="percentage" id="vatEditPercentage" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Effective At (optional)</label>
                        <input type="datetime-local" name="effective_at" id="vatEditEffective" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div class="flex items-center gap-2">
                        <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" value="1" id="vatEditActive" name="active">
                        <label class="text-sm text-slate-700" for="vatEditActive">Active</label>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('vatEditModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Disable VAT Modal --}}
<div class="relative z-50 hidden" id="vatDisableModal" aria-labelledby="vatDisableModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="vatDisableModal-title">Disable VAT</h3>
                <button type="button" onclick="closeModal('vatDisableModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form id="vatDisableForm" action="#" method="POST">
                @csrf
                <div class="p-5">
                    <p class="text-sm text-slate-600">This will create a new VAT record with 0% so taxes are effectively disabled. Proceed?</p>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('vatDisableModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600">Create 0% VAT</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Delete VAT Modal --}}
<div class="relative z-50 hidden" id="vatDeleteModal" aria-labelledby="vatDeleteModal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" aria-hidden="true"></div>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900" id="vatDeleteModal-title">Delete VAT</h3>
                <button type="button" onclick="closeModal('vatDeleteModal')" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <form id="vatDeleteForm" action="#" method="POST">
                @csrf @method('DELETE')
                <div class="p-5">
                    <p class="text-sm text-slate-600">Are you sure you want to delete this VAT record?</p>
                </div>
                <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-xl">
                    <button type="button" onclick="closeModal('vatDeleteModal')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openVatEdit(id, pct, eff, act){
    document.getElementById('vatEditPercentage').value = pct || '';
    document.getElementById('vatEditEffective').value = eff || '';
    document.getElementById('vatEditActive').checked = act === 1 || act === '1';
    document.getElementById('vatEditForm').action = '{{ url('/superadmin/vats') }}/'+id;
    openModal('vatEditModal');
}
function openVatDelete(id){
    document.getElementById('vatDeleteForm').action = '{{ url('/superadmin/vats') }}/'+id;
    openModal('vatDeleteModal');
}
function openVatDisable(id){
    document.getElementById('vatDisableForm').action = '{{ url('/superadmin/vats') }}/'+id+'/toggle';
    openModal('vatDisableModal');
}
</script>

@endsection
