@extends('admin.layout')
@section('subtitle', 'Feature CTA')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Feature CTAs</h2>
    <button type="button" onclick="openModal('createFeatureModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        New feature
    </button>
</div>

@if(session('success'))
<div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-sm text-emerald-700">{{ session('success') }}</div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-sky-50 border border-sky-200 text-sm text-sky-700">
            <i class="fi fi-rr-info text-sky-500"></i>
            <strong>Drag & Drop</strong> to reorder features. Changes are saved automatically.
        </div>
    </div>
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-[70px]">#</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Icon</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Title</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
                <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody id="sortable-features" class="divide-y divide-slate-50">
            @forelse($features as $feature)
                <tr data-id="{{ $feature->id }}" class="cursor-move hover:bg-slate-50/50">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <i class="fi fi-rr-menu-dots text-slate-300"></i>
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ $feature->order }}</span>
                        </div>
                    </td>
                    <td class="py-3 px-4">
                        <img src="{{ $feature->icon_url }}" alt="icon" class="w-8 h-8 rounded-full border border-slate-200 object-cover" />
                    </td>
                    <td class="py-3 px-4 text-slate-700">{{ $feature->title }}</td>
                    <td class="py-3 px-4 text-slate-600">{{ \Illuminate\Support\Str::limit($feature->description, 120) }}</td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-600 hover:text-slate-800 hover:bg-slate-100"
                                onclick="prepareEditFeature(@json($feature))">
                                <i class="fi fi-rr-pencil text-sm"></i>
                            </button>
                            <button type="button" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-600 hover:text-red-600 hover:bg-red-50"
                                onclick="prepareDeleteFeature('{{ route('admin.features.destroy', $feature) }}', '{{ addslashes($feature->title) }}')">
                                <i class="fi fi-rr-trash text-sm"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-12 text-center text-slate-400">No features found</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-slate-100">{{ $features->links() }}</div>
</div>

@include('admin.features.partials.create-modal')
@include('admin.features.partials.edit-modal')
@include('admin.features.partials.delete-modal')

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var sortableEl = document.getElementById('sortable-features');
        if (sortableEl && typeof Sortable !== 'undefined') {
            Sortable.create(sortableEl, {
                animation: 180,
                handle: 'tr',
                ghostClass: 'opacity-40',
                onEnd: function () {
                    var items = [];
                    var rows = sortableEl.querySelectorAll('tr[data-id]');
                    rows.forEach(function (row, index) {
                        items.push({
                            id: row.getAttribute('data-id'),
                            order: index + 1,
                        });
                    });

                    fetch('{{ route('admin.features.reorder') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({ items: items }),
                    });
                },
            });
        }
    });

    function prepareEditFeature(feature) {
        var form = document.getElementById('editFeatureForm');
        if (form) {
            form.action = '{{ url('superadmin/features') }}' + '/' + feature.id;
        }
        document.getElementById('edit-title').value = feature.title || '';
        document.getElementById('edit-description').value = feature.description || '';
        document.getElementById('edit-order').value = feature.order;
        var preview = document.getElementById('edit-icon-preview');
        if (preview) {
            if (feature.icon) {
                preview.style.backgroundImage = 'url(' + feature.icon + ')';
                preview.innerHTML = '';
            } else {
                preview.style.backgroundImage = 'none';
                preview.innerHTML = '<span class="text-slate-400 text-xs">No icon</span>';
            }
        }
        openModal('editFeatureModal');
    }

    function prepareDeleteFeature(action, title) {
        var form = document.getElementById('deleteFeatureForm');
        if (form) form.action = action;
        document.getElementById('delete-title').textContent = title || '';
        openModal('deleteFeatureModal');
    }
</script>
@endsection
