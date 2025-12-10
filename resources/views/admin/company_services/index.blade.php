@extends('admin.layout')
@section('subtitle', 'Company Services')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Company Services</h4>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createServiceModal">New service</button>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="alert alert-info mb-3">
        <i class="fa fa-info-circle me-2"></i>
        <strong>Drag & Drop</strong> to reorder services. Changes are saved automatically.
      </div>
      
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th width="50">#</th>
              <th>Title</th>
              <th>Page Link</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody id="sortable-services">
            @forelse($services as $s)
              <tr data-id="{{ $s->id }}" style="cursor: move;">
                <td>
                  <div class="d-flex align-items-center">
                    <i class="fa fa-grip-vertical text-muted me-2"></i>
                    <span class="badge bg-secondary">{{ $s->order }}</span>
                  </div>
                </td>
                <td>{{ $s->title }}</td>
                <td>
                  @if($s->page_link)
                    <code>/{{ $s->page_link }}</code>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td><span class="badge bg-{{ $s->status==='active'?'success':'secondary' }}">{{ $s->status }}</span></td>
                <td class="text-end">
                  <div class="btn-group" role="group" aria-label="Actions">
                    <a href="{{ $s->page_link ? url('/'.$s->page_link) : '#' }}" target="_blank" class="btn btn-light btn-sm border-0 @if(!$s->page_link) disabled @endif" title="Visit">
                      <i class="fa fa-external-link-alt fa-lg"></i>
                    </a>
                    <button type="button" class="btn btn-lg btn-primary" title="Edit"
                            data-bs-toggle="modal" data-bs-target="#editServiceModal"
                            data-id="{{ $s->id }}" data-title="{{ $s->title }}" data-description='@json($s->description)'
                            data-page_link="{{ $s->page_link }}" data-status="{{ $s->status }}" data-bg="{{ $s->background_image_path ? asset('storage/'.$s->background_image_path) : '' }}" data-order="{{ $s->order }}">
                      <i class="fa fa-pen"></i>
                    </button>
                    <button type="button" class="btn btn-lg" title="{{ $s->status==='active'?'Deactivate':'Activate' }}"
                            data-bs-toggle="modal" data-bs-target="#toggleServiceModal"
                            data-action="{{ route('admin.company-services.toggle', $s) }}" data-title="{{ $s->title }}" data-status="{{ $s->status }}">
                      @if($s->status==='active')
                        <i class="fa fa-toggle-on text-success"></i>
                      @else
                        <i class="fa fa-toggle-off text"></i>
                      @endif
                    </button>
                    <button type="button" class="btn btn-lg btn-danger" title="Delete"
                            data-bs-toggle="modal" data-bs-target="#deleteServiceModal"
                            data-action="{{ route('admin.company-services.destroy', $s) }}" data-title="{{ $s->title }}">
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-muted">No services found</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="mt-3">{{ $services->links() }}</div>
    </div>
  </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createServiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New service</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="{{ route('admin.company-services.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="modal-body">
          @if($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif
          <div class="mb-3">
            <label class="form-label">Display Order</label>
            <input type="number" name="order" class="form-control @error('order') is-invalid @enderror" value="{{ old('order', 0) }}" min="0">
            <div class="form-text">Lower numbers appear first (e.g., 1, 2, 3...)</div>
            @error('order')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required>
            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3">{{ old('description') }}</textarea>
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="mb-3">
            <label class="form-label">Page link</label>
            <div class="input-group">
              <span class="input-group-text">{{ url('/') }}/</span>
              <input type="text" name="page_link" class="form-control @error('page_link') is-invalid @enderror" value="{{ old('page_link') }}" placeholder="shop4me or main_store">
            </div>
            <div class="form-text">Enter a unique path without leading slash. Example: <code>shop4me</code></div>
            @error('page_link')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
          </div>
          <div class="mb-3">
            <label class="form-label">Background image</label>
            <input type="file" name="background_image" class="form-control @error('background_image') is-invalid @enderror" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">Accepted: jpg, jpeg, png, webp. Max size 10MB.</div>
            @error('background_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="mb-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
              <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>active</option>
              <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>inactive</option>
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editServiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit service</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="editServiceForm" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Display Order</label>
            <input type="number" name="order" id="edit-order" class="form-control" min="0">
            <div class="form-text">Lower numbers appear first (e.g., 1, 2, 3...)</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" name="title" id="edit-title" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="edit-description" class="form-control" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Page link</label>
            <div class="input-group">
              <span class="input-group-text">{{ url('/') }}/</span>
              <input type="text" name="page_link" id="edit-page_link" class="form-control" placeholder="shop4me or main_store">
            </div>
            <div class="form-text">Enter a unique path without leading slash. Example: <code>shop4me</code></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Background image</label>
            <input type="file" name="background_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">Accepted: jpg, jpeg, png, webp. Max size 10MB. Uploading a new file will replace the current one.</div>
            <div class="mt-2">
              <small class="text-muted d-block">Current:</small>
              <div id="edit-bg-preview" style="width: 180px; height: 80px; border-radius: 6px; background:#f8f9fa; overflow:hidden; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center;">
                <span class="text-muted small">No image</span>
              </div>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Status</label>
            <select name="status" id="edit-status" class="form-select" required>
              <option value="active">active</option>
              <option value="inactive">inactive</option>
            </select>
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

<!-- Toggle Status Modal -->
<div class="modal fade" id="toggleServiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="toggleServiceForm">
        @csrf
        <div class="modal-body">
          <div>Are you sure you want to <strong id="toggle-action"></strong> <strong id="toggle-title"></strong>?</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteServiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete service</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="deleteServiceForm">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <div>Are you sure you want to delete <strong id="delete-title"></strong>?</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function(){
    var editModal = document.getElementById('editServiceModal');
    if (editModal) {
      editModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var id = btn.getAttribute('data-id');
        var title = btn.getAttribute('data-title') || '';
        var description = btn.getAttribute('data-description') || '';
        var pageLink = btn.getAttribute('data-page_link') || '';
        var status = btn.getAttribute('data-status') || 'active';
        var order = btn.getAttribute('data-order') || '0';
        var bg = btn.getAttribute('data-bg') || '';
        var form = document.getElementById('editServiceForm');
        if (form) form.setAttribute('action', '{{ url('superadmin/company-services') }}' + '/' + id);
        document.getElementById('edit-order').value = order;
        document.getElementById('edit-title').value = title;
        document.getElementById('edit-description').value = description.replace(/^\"|\"$/g, '');
        document.getElementById('edit-page_link').value = pageLink;
        document.getElementById('edit-status').value = status;
        // update preview
        var prev = document.getElementById('edit-bg-preview');
        if (prev) {
          if (bg) {
            prev.innerHTML = '';
            prev.style.backgroundImage = 'url(' + bg + ')';
            prev.style.backgroundSize = 'cover';
            prev.style.backgroundPosition = 'center';
          } else {
            prev.style.backgroundImage = 'none';
            prev.innerHTML = '<span class="text-muted small">No image</span>';
          }
        }
      });
    }

    var toggleModal = document.getElementById('toggleServiceModal');
    if (toggleModal) {
      toggleModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var action = btn.getAttribute('data-action');
        var title = btn.getAttribute('data-title') || '';
        var status = btn.getAttribute('data-status') || 'active';
        var form = document.getElementById('toggleServiceForm');
        var actionEl = document.getElementById('toggle-action');
        var titleEl = document.getElementById('toggle-title');
        if (form) form.setAttribute('action', action);
        if (actionEl) actionEl.textContent = status==='active' ? 'deactivate' : 'activate';
        if (titleEl) titleEl.textContent = title;
      });
    }

    var deleteModal = document.getElementById('deleteServiceModal');
    if (deleteModal) {
      deleteModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var action = btn.getAttribute('data-action');
        var title = btn.getAttribute('data-title') || '';
        var form = document.getElementById('deleteServiceForm');
        var titleEl = document.getElementById('delete-title');
        if (form) form.setAttribute('action', action);
        if (titleEl) titleEl.textContent = title;
      });
    }

    // Auto-open create modal if there are validation errors
    @if($errors->any() && !request()->has('_method'))
      var createModal = new bootstrap.Modal(document.getElementById('createServiceModal'));
      createModal.show();
    @endif

    // Initialize drag-and-drop sorting
    var sortableEl = document.getElementById('sortable-services');
    if (sortableEl && typeof Sortable !== 'undefined') {
      var sortable = Sortable.create(sortableEl, {
        animation: 150,
        handle: 'tr',
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: function (evt) {
          // Get new order
          var items = [];
          var rows = sortableEl.querySelectorAll('tr[data-id]');
          rows.forEach(function(row, index) {
            items.push({
              id: row.getAttribute('data-id'),
              order: index + 1
            });
          });

          // Send to server
          fetch('{{ route("admin.company-services.reorder") }}', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ items: items })
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              // Update order badges
              rows.forEach(function(row, index) {
                var badge = row.querySelector('.badge.bg-secondary');
                if (badge) badge.textContent = index + 1;
              });
              
              // Show success message
              showToast('Order updated successfully!', 'success');
            } else {
              showToast('Failed to update order', 'error');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update order', 'error');
          });
        }
      });
    }

    function showToast(message, type) {
      // Simple toast notification
      var toast = document.createElement('div');
      toast.className = 'position-fixed top-0 end-0 p-3';
      toast.style.zIndex = '9999';
      toast.innerHTML = '<div class="toast show" role="alert"><div class="toast-body bg-' + 
        (type === 'success' ? 'success' : 'danger') + ' text-white">' + message + '</div></div>';
      document.body.appendChild(toast);
      setTimeout(function() {
        toast.remove();
      }, 3000);
    }
  });
</script>

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
  .sortable-ghost {
    opacity: 0.4;
    background: #f8f9fa;
  }
  .sortable-drag {
    opacity: 1;
    background: #fff;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
  }
  #sortable-services tr:hover {
    background-color: #f8f9fa;
  }
</style>
@endsection
