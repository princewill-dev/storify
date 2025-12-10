@extends('admin.layout')
@section('subtitle', 'Feature CTA')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Feature CTAs</h4>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFeatureModal">
            New feature
        </button>
    </div>

    @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="fa fa-info-circle me-2"></i>
                <strong>Drag & Drop</strong> to reorder features. Changes are saved automatically.
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th width="70">#</th>
                            <th>Icon</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sortable-features">
                        @forelse($features as $feature)
                            <tr data-id="{{ $feature->id }}" style="cursor: move;">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa fa-grip-vertical text-muted"></i>
                                        <span class="badge bg-secondary">{{ $feature->order }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="{{ $feature->icon_url }}" alt="icon" width="32" height="32" class="rounded-circle border" />
                                    </div>
                                </td>
                                <td>{{ $feature->title }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($feature->description, 120) }}</td>
                                <td class="text-end">
                                    <div class="btn-group" role="group" aria-label="Actions">
                                        <button type="button" class="btn btn-lg btn-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editFeatureModal"
                                            data-feature='@json($feature)'>
                                            <i class="fa fa-pen"></i>
                                        </button>
                                        <button type="button" class="btn btn-lg btn-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteFeatureModal"
                                            data-action="{{ route('admin.features.destroy', $feature) }}"
                                            data-title="{{ $feature->title }}">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">No features found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $features->links() }}</div>
        </div>
    </div>
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
                ghostClass: 'sortable-ghost',
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

        var editModal = document.getElementById('editFeatureModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var featureData = button.getAttribute('data-feature');
                var parsed = featureData ? JSON.parse(featureData) : {};
                var id = parsed.id;
                var title = parsed.title || '';
                var description = parsed.description || '';
                var order = parsed.order;
                var icon = parsed.icon;
                var form = document.getElementById('editFeatureForm');
                if (form) {
                    form.action = '{{ url('superadmin/features') }}' + '/' + id;
                }
                document.getElementById('edit-title').value = title;
                document.getElementById('edit-description').value = description;
                document.getElementById('edit-order').value = order;
                var preview = document.getElementById('edit-icon-preview');
                if (preview) {
                    preview.style.backgroundImage = icon ? 'url(' + icon + ')' : 'none';
                    preview.innerHTML = icon ? '' : '<span class="text-muted small">No icon</span>';
                }
            });
        }

        var deleteModal = document.getElementById('deleteFeatureModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');
                var title = button.getAttribute('data-title');
                var form = document.getElementById('deleteFeatureForm');
                if (form) {
                    form.action = action;
                }
                document.getElementById('delete-title').textContent = title;
            });
        }
    });
</script>


@endsection