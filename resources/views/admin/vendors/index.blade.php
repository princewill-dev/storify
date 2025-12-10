@extends('admin.layout')
@section('subtitle', 'Vendors')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Vendors</h6>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#filterVendorsModal">Filter</button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createVendorModal">Add Vendor</button>
                </div>
            </div>
            <div class="card-body">
                
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>KYC Status</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Stores</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($vendors as $vendor)
                                <tr>
                                    <td>{{ $vendor->name }}</td>
                                    <td>
                                        @php($kyc = $vendor->kycApplication)
                                        @php($kycBadge = $kyc ? ($kycStatusBadgeData[$kyc->status] ?? null) : null)
                                        @if($kyc)
                                            <span class="badge {{ $kycBadge['class'] ?? 'bg-secondary' }}">
                                                {{ $kycBadge['label'] ?? ucfirst(str_replace('_', ' ', $kyc->status)) }}
                                            </span>
                                            <a href="{{ route('admin.vendor-kyc.show', $kyc) }}" class="small ms-2"> <i class="fas fa-external-link-alt"></i></a>
                                        @else
                                            <span class="text-muted small">Not started</span>
                                        @endif
                                    </td>
                                    <td>{{ $vendor->email }}</td>
                                    <td>{{ $vendor->phone }}</td>
                                    <td>
                                        @php($shownStores = $vendor->stores ?? collect())
                                        @forelse($shownStores as $s)
                                            <a href="{{ route('admin.stores.show', $s) }}" class="badge bg-light text-dark text-decoration-none me-1">{{ $s->name }}</a>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                        @php($extraCount = max(0, (int)($vendor->stores_count ?? 0) - $shownStores->count()))
                                        @if($extraCount > 0)
                                            <a href="{{ route('admin.vendors.show', $vendor) }}" class="small text-muted">+{{ $extraCount }} more</a>
                                        @endif
                                    </td>
                                    <td>
                                        @php($vendorBadge = $vendorStatusBadgeData[strtolower($vendor->status)] ?? null)
                                        <span class="badge {{ $vendorBadge['class'] ?? 'bg-secondary' }}">
                                            {{ $vendorBadge['label'] ?? ucfirst($vendor->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm border-0 bg-transparent text-dark" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Vendor actions">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="{{ route('admin.vendors.show', $vendor) }}">
                                                        <i class="fa fa-eye me-2 text-muted"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#editVendorModal"
                                                            data-action="{{ route('admin.vendors.update', $vendor) }}"
                                                            data-name="{{ $vendor->name }}"
                                                            data-slug="{{ $vendor->slug }}"
                                                            data-email="{{ $vendor->email }}"
                                                            data-phone="{{ $vendor->phone }}"
                                                            data-status="{{ $vendor->status }}">
                                                        <i class="fa fa-pen me-2 text-muted"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#activateVendorModal"
                                                            data-action="{{ route('admin.vendors.activate', $vendor) }}"
                                                            data-vendor-name="{{ $vendor->name }}">
                                                        <i class="fa fa-check me-2 text-muted"></i>Activate
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#suspendVendorModal"
                                                            data-action="{{ route('admin.vendors.suspend', $vendor) }}"
                                                            data-vendor-name="{{ $vendor->name }}">
                                                        <i class="fa fa-ban me-2 text-muted"></i>Suspend
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#deleteVendorModal"
                                                            data-action="{{ route('admin.vendors.destroy', $vendor) }}"
                                                            data-vendor-name="{{ $vendor->name }}">
                                                        <i class="fa fa-trash me-2 text-muted"></i>Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">No vendors yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">{{ $vendors->links() }}</div>
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

<!-- Create Vendor Modal -->
<div class="modal fade" id="createVendorModal" tabindex="-1" aria-labelledby="createVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createVendorLabel">Add Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="{{ route('admin.vendors.store') }}" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>
          <div class="mb-3" style="display: none;">
            <label class="form-label">Slug</label>
            <input type="text" name="slug" class="form-control" value="{{ old('slug') }}" placeholder="auto-generated from name if left blank">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="{{ old('email') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active" {{ old('status','active')=='active'?'selected':'' }}>active</option>
              <option value="inactive" {{ old('status')=='inactive'?'selected':'' }}>inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
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
<!-- Filter Vendors Modal -->
<div class="modal fade" id="filterVendorsModal" tabindex="-1" aria-labelledby="filterVendorsLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filterVendorsLabel">Filter Vendors</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="GET">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="">All</option>
                <option value="active" @selected(($status ?? '')==='active')>Active</option>
                <option value="suspended" @selected(($status ?? '')==='suspended')>Suspended</option>
                <option value="deleted" @selected(($status ?? '')==='deleted')>Deleted</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Search</label>
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Name, email or phone">
            </div>
            <div class="col-md-4">
              <label class="form-label">From</label>
              <input type="date" name="from" value="{{ $from ?? '' }}" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">To</label>
              <input type="date" name="to" value="{{ $to ?? '' }}" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="{{ route('admin.vendors.index') }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
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

<!-- Activate Vendor Modal -->
<div class="modal fade" id="activateVendorModal" tabindex="-1" aria-labelledby="activateVendorLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="activateVendorLabel">Activate Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="activateVendorForm" method="POST">
        @csrf
        <div class="modal-body">
          <div class="alert alert-info">
            <strong>ℹ️ Note:</strong> Activating this vendor will also approve their KYC submission. This action means you are okay with the vendor's KYC submission.
          </div>
          <div class="mb-3">
            <label class="form-label">Vendor</label>
            <input type="text" class="form-control" id="activateVendorName" disabled>
          </div>
          <div class="mb-3">
            <label for="activateReason" class="form-label">Reason / Notes</label>
            <textarea class="form-control" id="activateReason" name="reason" rows="4" placeholder="Provide reason for activation" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Activate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('suspendVendorModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var vendorName = button.getAttribute('data-vendor-name');
    var nameInput = document.getElementById('suspendVendorName');
    var form = document.getElementById('suspendVendorForm');
    if (nameInput) nameInput.value = vendorName || '';
    if (form && action) {
      form.action = action;
    }
  });
});

// Delete Vendor modal population
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('deleteVendorModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var name = button.getAttribute('data-vendor-name');
    var form = document.getElementById('deleteVendorForm');
    var nameInput = document.getElementById('deleteVendorName');
    if (form && action) form.action = action;
    if (nameInput) nameInput.value = name || '';
  });
});

// Edit Vendor modal population
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('editVendorModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) return;
    var action = button.getAttribute('data-action');
    var name = button.getAttribute('data-name') || '';
    var slug = button.getAttribute('data-slug') || '';
    var email = button.getAttribute('data-email') || '';
    var phone = button.getAttribute('data-phone') || '';
    var status = (button.getAttribute('data-status') || '').toLowerCase();

    var form = document.getElementById('editVendorForm');
    if (form && action) form.action = action;
    document.getElementById('editVendorName').value = name;
    document.getElementById('editVendorSlug').value = slug;
    document.getElementById('editVendorEmail').value = email;
    document.getElementById('editVendorPhone').value = phone;
    var statusSelect = document.getElementById('editVendorStatus');
    if (statusSelect) {
      Array.from(statusSelect.options).forEach(function(opt){ opt.selected = (opt.value.toLowerCase() === status); });
    }
  });
});

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('activateVendorModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var vendorName = button.getAttribute('data-vendor-name');
    var nameInput = document.getElementById('activateVendorName');
    var form = document.getElementById('activateVendorForm');
    if (nameInput) nameInput.value = vendorName || '';
    if (form && action) {
      form.action = action;
    }
  });
});
</script>
@endsection
