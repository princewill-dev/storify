@extends('admin.layout')
@section('subtitle', 'Vendor details')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Vendor: {{ $user->name }}</h4>
    <div>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editVendorModal" 
              data-vendor-id="{{ $user->id }}" data-vendor-name="{{ $user->name }}" 
              data-vendor-account-id="{{ $user->account_id }}" data-vendor-slug="{{ $user->slug }}" 
              data-vendor-email="{{ $user->email }}" data-vendor-phone="{{ $user->phone }}" 
              data-vendor-status="{{ $user->status }}">
        <i class="fa fa-edit"></i> Edit
      </button>
      <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#suspendVendorModal" data-vendor-account-id="{{ $user->account_id }}" data-vendor-name="{{ $user->name }}">
        <i class="fa fa-ban"></i> Suspend
      </button>
      <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteVendorModal" data-vendor-account-id="{{ $user->account_id }}" data-vendor-name="{{ $user->name }}">
        <i class="fa fa-trash"></i> Delete
      </button>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Account Details</strong></div>
        <div class="card-body">
          <div class="row mb-2">
            <div class="col-md-6">
              <div class="text-muted small">Email</div>
              <div>{{ $user->email ?? '—' }}</div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Phone</div>
              <div>{{ $user->phone ?? '—' }}</div>
            </div>
          </div>
          <div class="row mb-2">
            <div class="col-md-6">
              <div class="text-muted small">Status</div>
              <span class="badge bg-light text-dark">{{ $user->status }}</span>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Date Registered</div>
              <div>{{ optional($user->created_at)->format('Y-m-d H:i') }}</div>
            </div>
          </div>
          <div class="row mb-2">
            <div class="col-md-6">
              <div class="text-muted small">Email Verification</div>
              @if(isset($user->email_verified_at) && $user->email_verified_at)
                <span class="badge bg-success">Verified</span>
              @else
                <span class="badge bg-secondary">Unverified</span>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Stores ({{ $user->stores->count() }})</strong>
          <a href="{{ route('admin.stores.create') }}" class="btn btn-sm btn-outline-secondary">Add Store</a>
        </div>
        <div class="card-body">
          @if($user->stores->isEmpty())
            <div class="text-muted">No stores yet.</div>
          @else
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Store</th>
                    <th>Ownership</th>
                    <th>Business</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($user->stores as $s)
                    <tr>
                      <td>
                        <a href="{{ route('admin.stores.show', $s) }}">{{ $s->name }}</a>
                        <div class="small text-muted"><code>{{ $s->store_id }}</code></div>
                      </td>
                      <td>{{ $s->ownershipType?->name ?? '—' }}</td>
                      <td>{{ $s->businessType?->name ?? '—' }}</td>
                      <td><span class="badge bg-light text-dark">{{ $s->status }}</span></td>
                      <td class="text-end">
                        <a href="{{ route('admin.products.create', ['store_id' => $s->store_id]) }}" class="btn btn-xs btn-outline-secondary">Add Product</a>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Delete Vendor Modal -->
<div class="modal fade" id="deleteVendorModal" tabindex="-1" aria-labelledby="deleteVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteVendorLabel">Delete Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="deleteVendorForm" method="POST" action="#">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <p class="mb-1">You're about to delete this vendor:</p>
          <input type="text" id="deleteVendorName" class="form-control" disabled>
          <p class="mt-3 mb-0 text-danger small">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Vendor Modal -->
<div class="modal fade" id="editVendorModal" tabindex="-1" aria-labelledby="editVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editVendorLabel">Edit Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editVendorForm" action="#" method="POST">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" id="editVendorName" class="form-control" required>
          </div>
          <div class="mb-3" style="display: none;">
            <label class="form-label">Slug</label>
            <input type="text" name="slug" id="editVendorSlug" class="form-control" placeholder="auto-generated from name if left blank">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="editVendorEmail" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="editVendorPhone" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="editVendorStatus" class="form-select">
              <option value="active">active</option>
              <option value="inactive">inactive</option>
              <option value="suspended">suspended</option>
              <option value="deleted">deleted</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Suspend Vendor Modal -->
<div class="modal fade" id="suspendVendorModal" tabindex="-1" aria-labelledby="suspendVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="suspendVendorLabel">Suspend Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="suspendVendorForm" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Vendor</label>
            <input type="text" class="form-control" id="suspendVendorName" disabled>
          </div>
          <div class="mb-3">
            <label for="suspendReason" class="form-label">Reason</label>
            <textarea class="form-control" id="suspendReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Suspend</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    // Delete Vendor Modal
    var deleteModal = document.getElementById('deleteVendorModal');
    if (deleteModal) {
      deleteModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var vendorAccountId = button.getAttribute('data-vendor-account-id');
        var vendorName = button.getAttribute('data-vendor-name');
        var nameInput = document.getElementById('deleteVendorName');
        var form = document.getElementById('deleteVendorForm');
        if (nameInput) nameInput.value = vendorName || '';
        if (form && vendorAccountId) {
          // Use account_id for delete action (will redirect to index after delete)
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId;
        }
      });
    }

    // Edit Vendor Modal
    var editModal = document.getElementById('editVendorModal');
    if (editModal) {
      editModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var vendorAccountId = button.getAttribute('data-vendor-account-id');
        var vendorName = button.getAttribute('data-vendor-name');
        var vendorSlug = button.getAttribute('data-vendor-slug');
        var vendorEmail = button.getAttribute('data-vendor-email');
        var vendorPhone = button.getAttribute('data-vendor-phone');
        var vendorStatus = button.getAttribute('data-vendor-status');
        
        document.getElementById('editVendorName').value = vendorName || '';
        document.getElementById('editVendorSlug').value = vendorSlug || '';
        document.getElementById('editVendorEmail').value = vendorEmail || '';
        document.getElementById('editVendorPhone').value = vendorPhone || '';
        document.getElementById('editVendorStatus').value = vendorStatus || 'active';
        
        var form = document.getElementById('editVendorForm');
        if (form && vendorAccountId) {
          // Use account_id for show page, add redirect parameter
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId + "?redirect=show";
        }
      });
    }

    // Suspend Vendor Modal
    var suspendModal = document.getElementById('suspendVendorModal');
    if (suspendModal) {
      suspendModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var vendorAccountId = button.getAttribute('data-vendor-account-id');
        var vendorName = button.getAttribute('data-vendor-name');
        var nameInput = document.getElementById('suspendVendorName');
        var form = document.getElementById('suspendVendorForm');
        if (nameInput) nameInput.value = vendorName || '';
        if (form && vendorAccountId) {
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId + "/suspend";
        }
      });
    }
  });
</script>
@endsection
