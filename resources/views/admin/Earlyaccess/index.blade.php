@extends('admin.layout')

@section('content')
<div class="row">
    <div class="col-xl-12">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h4 class="mb-0">Early Access Codes</h4>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="fi fi-rr-plus me-2"></i>Create New Code
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Usage Count</th>
                                <th>Created At</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($passes as $pass)
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary">{{ $pass->code }}</span>
                                </td>
                                <td>{{ $pass->description ?? '-' }}</td>
                                <td>
                                    @if($pass->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-danger">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                         <span class="fw-bold me-2">
                                             {{ $pass->usages_count }} 
                                             <span class="text-muted fw-normal">/ {{ $pass->max_uses ?? '∞' }}</span>
                                         </span>
                                         <a href="{{ route('admin.early-access.show', $pass) }}" class="text-primary small text-decoration-none">View Details</a>
                                    </div>
                                </td>
                                <td>{{ $pass->created_at?->format('d M Y H:i') }}</td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fi fi-rr-menu-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="{{ route('admin.early-access.show', $pass) }}">
                                                    <i class="fi fi-rr-eye me-2"></i>View Usages
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#editModal" 
                                                   data-id="{{ $pass->id }}"
                                                   data-code="{{ $pass->code }}"
                                                   data-description="{{ $pass->description }}"
                                                   data-max-uses="{{ $pass->max_uses }}">
                                                    <i class="fi fi-rr-edit me-2"></i>Edit
                                                </a>
                                            </li>
                                            <li>
                                                <form action="{{ route('admin.early-access.toggle-status', $pass) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item">
                                                        @if($pass->is_active)
                                                            <i class="fi fi-rr-ban me-2 text-warning"></i>Deactivate
                                                        @else
                                                            <i class="fi fi-rr-check me-2 text-success"></i>Activate
                                                        @endif
                                                    </button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#deleteModal" 
                                                   data-id="{{ $pass->id }}"
                                                   data-code="{{ $pass->code }}">
                                                    <i class="fi fi-rr-trash me-2"></i>Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="empty-state">
                                        <i class="fi fi-rr-ticket fs-1 mb-3 d-block text-muted"></i>
                                        <h5 class="text-muted">No early access codes found</h5>
                                        <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#createModal">
                                            Create First Code
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    {{ $passes->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Early Access Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('admin.early-access.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="code" name="code" required minlength="3" placeholder="e.g. EARLYBIRD2025">
                        <div class="form-text">Codes are handled in uppercase.</div>
                    </div>
                    <div class="mb-3">
                        <label for="max_uses" class="form-label">Max Uses (Optional)</label>
                        <input type="number" class="form-control" id="max_uses" name="max_uses" min="1" placeholder="Leave blank for unlimited">
                        <div class="form-text">Code passes will auto-deactivate when limit is reached.</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Internal note about this code..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Early Access Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editForm" action="" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_code" class="form-label">Code</label>
                        <input type="text" class="form-control" id="edit_code" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="edit_max_uses" class="form-label">Max Uses</label>
                        <input type="number" class="form-control" id="edit_max_uses" name="max_uses" min="1" placeholder="Unlimited">
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this code? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form id="deleteForm" action="" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle Edit Modal
        var editModal = document.getElementById('editModal');
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-id');
            var code = button.getAttribute('data-code');
            var description = button.getAttribute('data-description');
            var maxUses = button.getAttribute('data-max-uses');
            
            var modalCodeInput = editModal.querySelector('#edit_code');
            var modalDescInput = editModal.querySelector('#edit_description');
            var modalMaxUsesInput = editModal.querySelector('#edit_max_uses');
            var form = editModal.querySelector('#editForm');
            
            modalCodeInput.value = code;
            modalDescInput.value = description;
            modalMaxUsesInput.value = maxUses;
            form.action = '/superadmin/early-access/' + code;
        });

        // Handle Delete Modal
        var deleteModal = document.getElementById('deleteModal');
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var code = button.getAttribute('data-code');
            var form = deleteModal.querySelector('#deleteForm');
            
            form.action = '/superadmin/early-access/' + code;
        });
    });
</script>
@endpush
@endsection
