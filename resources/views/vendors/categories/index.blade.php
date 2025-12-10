@extends('vendors.layout')
@section('subtitle', 'Categories')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Categories</h4>
    <div class="d-flex gap-2">
      <a href="{{ route('vendor.categories.create', ['vendor' => $vendor]) }}" class="btn btn-primary">New category</a>
      <a href="{{ route('vendor.dashboard') }}" class="btn btn-light">Back</a>
    </div>
  </div>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Store</th>
            <th>Name</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($categories as $c)
            <tr>
              <td>{{ $c->store?->name }}</td>
              <td>{{ $c->name }}</td>
              <td><span class="badge bg-{{ $c->status==='active'?'success':'secondary' }}">{{ $c->status }}</span></td>
              <td class="text-end">
                <a href="{{ route('vendor.categories.edit', ['vendor' => $vendor, 'category' => $c]) }}" class="btn btn-sm p-1 border-0 bg-transparent text-primary" title="Edit">
                  <i class="fa fa-pen"></i>
                </a>
                <button type="button" class="btn btn-sm p-1 border-0 bg-transparent text-danger" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-action="{{ route('vendor.categories.destroy', ['vendor' => $vendor, 'category' => $c]) }}" data-name="{{ $c->name }}" title="Delete">
                  <i class="fa fa-trash"></i>
                </button>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted">No categories</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-3">{{ $categories->links() }}</div>
    </div>
  </div>
</div>

<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="deleteCategoryForm">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <div>Are you sure you want to delete <strong id="del-cat-name"></strong>?</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Create Category Modal (available on both scoped and global pages) -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="{{ route('admin.categories.store') }}">
        @csrf
        <div class="modal-body">
          @if(!empty($store))
            <input type="hidden" name="store_id" value="{{ $store->id }}">
          @else
            <div class="mb-3">
              <label class="form-label">Store</label>
              <select name="store_id" class="form-select" required>
                <option value="">Select store</option>
                @foreach(($stores ?? collect()) as $s)
                  <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
              </select>
            </div>
          @endif
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="active">active</option>
              <option value="inactive">inactive</option>
            </select>
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

<script>
document.addEventListener('DOMContentLoaded', function(){
  var modal = document.getElementById('deleteCategoryModal');
  if(!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    var action = btn.getAttribute('data-action');
    var name = btn.getAttribute('data-name') || '';
    var form = document.getElementById('deleteCategoryForm');
    var nameEl = document.getElementById('del-cat-name');
    if(form) form.setAttribute('action', action);
    if(nameEl) nameEl.textContent = name;
  });
});
</script>
@endsection
