@extends('admin.layout')
@section('subtitle', $business ? $business->name : $user->name)

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">
        {{ $business?->name ?? $user->name }}
        @if($business)<small class="text-muted font-monospace fs-6 ms-2">{{ $business->business_code }}</small>@endif
      </h4>
      @if($business?->prefix)<div class="text-muted small">Prefix: {{ $business->prefix }}</div>@endif
    </div>
    <div>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editVendorModal"
              data-vendor-account-id="{{ $user->account_id }}" data-vendor-name="{{ $user->name }}"
              data-vendor-slug="{{ $user->slug }}" data-vendor-email="{{ $user->email }}"
              data-vendor-phone="{{ $user->phone }}" data-vendor-status="{{ $user->status }}">
        <i class="fa fa-edit"></i> Edit Owner
      </button>
      <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#suspendVendorModal" data-vendor-account-id="{{ $user->account_id }}" data-vendor-name="{{ $business?->name ?? $user->name }}">
        <i class="fa fa-ban"></i> Suspend
      </button>
      <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteVendorModal" data-vendor-account-id="{{ $user->account_id }}" data-vendor-name="{{ $business?->name ?? $user->name }}">
        <i class="fa fa-trash"></i> Delete
      </button>
    </div>
  </div>

  @if($business)
  {{-- Summary cards --}}
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card">
        <div class="card-body text-center py-3">
          <h3 class="mb-0 fw-bold">{{ $business->stores_count }}</h3>
          <small class="text-muted">Stores</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body text-center py-3">
          <h3 class="mb-0 fw-bold">{{ $business->warehouses_count }}</h3>
          <small class="text-muted">Warehouses</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body text-center py-3">
          <h3 class="mb-0 fw-bold">{{ $business->users_count }}</h3>
          <small class="text-muted">Team Members</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card">
        <div class="card-body text-center py-3">
          @php($sub = $business->activeSubscription)
          @if($sub)
            <h5 class="mb-0 fw-bold text-success">{{ $sub->subscriptionPlan?->name ?? 'Active' }}</h5>
            <small class="text-muted">{{ $sub->ends_at ? 'Until ' . $sub->ends_at->format('d M Y') : 'Active' }}</small>
          @else
            <h5 class="mb-0 fw-bold text-secondary">No Plan</h5>
            <small class="text-muted">Subscription</small>
          @endif
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    {{-- Business & Owner details --}}
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Business Details</strong></div>
        <div class="card-body">
          <table class="table table-sm">
            <tr><td class="text-muted" style="width: 140px">Name</td><td><strong>{{ $business->name }}</strong></td></tr>
            <tr><td class="text-muted">Code</td><td><code>{{ $business->business_code }}</code></td></tr>
            <tr><td class="text-muted">Status</td><td><span class="badge bg-{{ $business->status === 'active' ? 'success' : ($business->status === 'suspended' ? 'warning' : 'secondary') }}">{{ $business->status }}</span></td></tr>
            <tr><td class="text-muted">Created</td><td>{{ $business->created_at->format('d M Y, H:i') }}</td></tr>
          </table>

          <hr>
          <h6 class="mb-2">Owner</h6>
          <table class="table table-sm">
            <tr><td class="text-muted" style="width: 140px">Name</td><td>{{ $business->owner?->name ?? '—' }}</td></tr>
            <tr><td class="text-muted">Email</td><td>{{ $business->owner?->email ?? '—' }}</td></tr>
            <tr><td class="text-muted">Phone</td><td>{{ $business->owner?->phone ?? '—' }}</td></tr>
            <tr><td class="text-muted">Status</td><td>{{ $business->owner?->status ?? '—' }}</td></tr>
            <tr><td class="text-muted">Email Verified</td><td>
              @if($business->owner?->email_verified_at)
                <span class="badge bg-success">Verified</span>
              @else
                <span class="badge bg-secondary">Unverified</span>
              @endif
            </td></tr>
          </table>
        </div>
      </div>
    </div>

    {{-- Team members --}}
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Team ({{ $business->users->count() }})</strong></div>
        <div class="card-body">
          @if($business->users->isEmpty())
            <div class="text-muted">No team members.</div>
          @else
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>
                <tbody>
                  @foreach($business->users as $member)
                    <tr>
                      <td>{{ $member->name }}</td>
                      <td><small>{{ $member->email }}</small></td>
                      <td>
                        @foreach($member->roles as $role)
                          <span class="badge bg-light text-dark me-1">{{ $role->name }}</span>
                        @endforeach
                      </td>
                      <td><span class="badge bg-{{ $member->status === 'active' ? 'success' : 'secondary' }}">{{ $member->status }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>

    {{-- Stores --}}
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Stores ({{ $business->stores->count() }})</strong>
          <a href="{{ route('admin.stores.create') }}" class="btn btn-sm btn-outline-secondary">Add Store</a>
        </div>
        <div class="card-body">
          @if($business->stores->isEmpty())
            <div class="text-muted">No stores yet.</div>
          @else
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>Store</th><th>Ownership</th><th>Business Type</th><th>Status</th></tr></thead>
                <tbody>
                  @foreach($business->stores as $s)
                    <tr>
                      <td>
                        <a href="{{ route('admin.stores.show', $s) }}">{{ $s->name }}</a>
                        <div class="small text-muted"><code>{{ $s->store_id }}</code></div>
                      </td>
                      <td>{{ $s->ownershipType?->name ?? '—' }}</td>
                      <td>{{ $s->businessType?->name ?? '—' }}</td>
                      <td><span class="badge bg-light text-dark">{{ $s->status }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>

    {{-- Warehouses --}}
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Warehouses ({{ $business->warehouses->count() }})</strong></div>
        <div class="card-body">
          @if($business->warehouses->isEmpty())
            <div class="text-muted">No warehouses yet.</div>
          @else
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>Name</th><th>Code</th><th>Stock Items</th><th>Status</th></tr></thead>
                <tbody>
                  @foreach($business->warehouses as $wh)
                    <tr>
                      <td>{{ $wh->name }}</td>
                      <td><code>{{ $wh->warehouse_code }}</code></td>
                      <td>{{ $wh->stock_locations_count }}</td>
                      <td><span class="badge bg-{{ $wh->status === 'active' ? 'success' : 'secondary' }}">{{ $wh->status }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>

    {{-- KYC --}}
    @php($kyc = $business->kycApplications->first())
    <div class="col-12">
      <div class="card">
        <div class="card-header"><strong>KYC Verification</strong></div>
        <div class="card-body">
          @if($kyc)
            <table class="table table-sm">
              <tr><td class="text-muted" style="width: 140px">Status</td><td><span class="badge bg-{{ $kyc->status === 'approved' ? 'success' : ($kyc->status === 'rejected' ? 'danger' : 'warning') }}">{{ $kyc->status }}</span></td></tr>
              <tr><td class="text-muted">Submitted</td><td>{{ $kyc->created_at->format('d M Y, H:i') }}</td></tr>
              @if($kyc->reviewed_by)<tr><td class="text-muted">Reviewed by</td><td>Admin #{{ $kyc->reviewed_by }} on {{ optional($kyc->reviewed_at)->format('d M Y') }}</td></tr>@endif
            </table>
            <a href="{{ route('admin.vendor-kyc.show', $kyc) }}" class="btn btn-sm btn-outline-primary">View KYC Details</a>
          @else
            <div class="text-muted">No KYC application submitted yet.</div>
          @endif
        </div>
      </div>
    </div>
  </div>
  @else
  {{-- Fallback: old vendor view (no Business record) --}}
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Account Details</strong></div>
        <div class="card-body">
          <table class="table table-sm">
            <tr><td class="text-muted" style="width: 140px">Name</td><td>{{ $user->name }}</td></tr>
            <tr><td class="text-muted">Email</td><td>{{ $user->email ?? '—' }}</td></tr>
            <tr><td class="text-muted">Phone</td><td>{{ $user->phone ?? '—' }}</td></tr>
            <tr><td class="text-muted">Status</td><td><span class="badge bg-light text-dark">{{ $user->status }}</span></td></tr>
            <tr><td class="text-muted">Registered</td><td>{{ optional($user->created_at)->format('Y-m-d H:i') }}</td></tr>
          </table>
          <div class="alert alert-warning mb-0">This vendor has no Business record yet. The old vendor flow is deprecated.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Stores</strong></div>
        <div class="card-body">
          @forelse($user->stores as $s)
            <a href="{{ route('admin.stores.show', $s) }}">{{ $s->name }}</a>{{ !$loop->last ? ', ' : '' }}
          @empty
            <div class="text-muted">No stores.</div>
          @endforelse
        </div>
      </div>
    </div>
  </div>
  @endif
</div>

{{-- Modals (same as before, operate on owner User) --}}
@include('admin.vendors._modals')
@endsection

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', function() {
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
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId;
        }
      });
    }

    var editModal = document.getElementById('editVendorModal');
    if (editModal) {
      editModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var vendorAccountId = button.getAttribute('data-vendor-account-id');
        document.getElementById('editVendorName').value = button.getAttribute('data-vendor-name') || '';
        document.getElementById('editVendorSlug').value = button.getAttribute('data-vendor-slug') || '';
        document.getElementById('editVendorEmail').value = button.getAttribute('data-vendor-email') || '';
        document.getElementById('editVendorPhone').value = button.getAttribute('data-vendor-phone') || '';
        document.getElementById('editVendorStatus').value = button.getAttribute('data-vendor-status') || 'active';
        var form = document.getElementById('editVendorForm');
        if (form && vendorAccountId) {
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId + "?redirect=show";
        }
      });
    }

    var suspendModal = document.getElementById('suspendVendorModal');
    if (suspendModal) {
      suspendModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var vendorAccountId = button.getAttribute('data-vendor-account-id');
        var vendorName = button.getAttribute('data-vendor-name');
        document.getElementById('suspendVendorName').value = vendorName || '';
        var form = document.getElementById('suspendVendorForm');
        if (form && vendorAccountId) {
          form.action = "{{ url('superadmin/vendors') }}/" + vendorAccountId + "/suspend";
        }
      });
    }
  });
</script>
@endpush
