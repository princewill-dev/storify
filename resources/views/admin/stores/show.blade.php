@extends('admin.layout')
@section('subtitle', $store->name)

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Store: {{ $store->name }}</h4>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-primary btn-sm"
              data-bs-toggle="modal" data-bs-target="#editStoreModal"
              data-action="{{ route('admin.stores.update', $store) }}"
              data-business-id="{{ $store->business_id }}"
              data-name="{{ $store->name }}"
              data-slug="{{ $store->slug }}"
              data-description="{{ $store->description }}"
              data-support-email="{{ $store->support_email }}"
              data-support-phone="{{ $store->support_phone }}"
              data-address="{{ $store->address }}"
              data-instagram-url="{{ $store->instagram_url }}"
              data-facebook-url="{{ $store->facebook_url }}"
              data-twitter-url="{{ $store->twitter_url }}"
              data-tiktok-url="{{ $store->tiktok_url }}"
              data-ownership-type-id="{{ $store->ownership_type_id }}"
              data-business-type-id="{{ $store->business_type_id }}"
              data-status="{{ $store->status }}"
              data-logo-url="{{ $store->logo_path ? asset('storage/'.$store->logo_path) : '' }}">
        Edit Store
      </button>
      @if(strtolower($store->status) === 'suspended')
        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#activateStoreModal" data-action="{{ route('admin.stores.activate', $store) }}" data-store-name="{{ $store->name }}">Activate</button>
      @else
        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#suspendStoreModal" data-action="{{ route('admin.stores.suspend', $store) }}" data-store-name="{{ $store->name }}">Suspend</button>
      @endif
      <a href="{{ route('admin.stores.product.create', $store) }}" class="btn btn-outline-secondary btn-sm">Add Product</a>
      <a href="{{ route('admin.stores.categories.create', $store) }}" class="btn btn-outline-secondary btn-sm">Add Category</a>
      <a href="{{ route('admin.stores.products.index', $store) }}" class="btn btn-outline-secondary btn-sm">Manage Products</a>
      <a href="{{ route('admin.stores.categories.index', $store) }}" class="btn btn-outline-secondary btn-sm">Manage Categories</a>
      <a href="{{ route('admin.storefront-slides.index', $store) }}" class="btn btn-outline-primary btn-sm">Edit slides</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Total amount earned</div>
          <div class="fs-4 fw-bold">₦0.00</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Customers</div>
          <div class="fs-4 fw-bold">0</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Products</div>
          <div class="fs-4 fw-bold">{{ $productCount }}</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Sales</div>
          <div class="fs-4 fw-bold">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Store Info</strong></div>
        <div class="card-body">
          <div class="d-flex align-items-center mb-3 gap-3">
            @if($store->logo_path)
              <img src="{{ asset('storage/'.$store->logo_path) }}" alt="" style="width:56px;height:56px;object-fit:contain;border-radius:6px;border:1px solid #eee;">
            @endif
            <div>
              <div class="fw-semibold">{{ $store->name }}</div>
              <div class="text-muted small">ID: <code>{{ $store->store_id }}</code> • Status: <span class="badge bg-light text-dark">{{ $store->status }}</span></div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-12">
              <div class="text-muted small">Description</div>
              <div>{{ $store->description ?? '—' }}</div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Support Email</div>
              <div>{{ $store->support_email ?? '—' }}</div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Support Phone</div>
              <div>{{ $store->support_phone ?? '—' }}</div>
            </div>
          </div>
          <div class="row mt-2">
            <div class="col-md-4">
              <div class="text-muted small">Ownership</div>
              <div>{{ $store->ownershipType?->name ?? '—' }}</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Type</div>
              <div>{{ $store->businessType?->name ?? '—' }}</div>
            </div>
            <div class="col-md-4">
              <div class="text-muted small">Business</div>
              <div>
                @if($store->business)
                  <a href="{{ route('admin.vendors.show', $store->vendor) }}">{{ $store->business->name }}</a>
                @else
                  —
                @endif
              </div>
            </div>
          </div>
          <div class="mt-3">
            <div class="text-muted small">Address</div>
            <div>{{ $store->address ?? '—' }}</div>
          </div>
          <div class="mt-3">
            <div class="text-muted small mb-1">Social Links</div>
            <div class="d-flex flex-wrap gap-2">
              @if($store->instagram_url)
                <a href="{{ $store->instagram_url }}" target="_blank" class="btn btn-sm btn-outline-secondary">Instagram</a>
              @endif
              @if($store->facebook_url)
                <a href="{{ $store->facebook_url }}" target="_blank" class="btn btn-sm btn-outline-secondary">Facebook</a>
              @endif
              @if($store->twitter_url)
                <a href="{{ $store->twitter_url }}" target="_blank" class="btn btn-sm btn-outline-secondary">Twitter</a>
              @endif
              @if($store->tiktok_url)
                <a href="{{ $store->tiktok_url }}" target="_blank" class="btn btn-sm btn-outline-secondary">TikTok</a>
              @endif
              @if(!$store->instagram_url && !$store->facebook_url && !$store->twitter_url && !$store->tiktok_url)
                <div class="text-muted small">—</div>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Business & Owner</strong></div>
        <div class="card-body">
          @if($store->business)
            <div class="fw-semibold">{{ $store->business->name }}</div>
            <div class="text-muted small font-monospace">{{ $store->business->business_code }}</div>
            <hr>
            <div class="text-muted small">Owner</div>
            <div>{{ $store->vendor?->name ?? '—' }}</div>
            <div class="text-muted small">Email: {{ $store->vendor?->email ?? '—' }}</div>
            <div class="text-muted small">Phone: {{ $store->vendor?->phone ?? '—' }}</div>
          @elseif($store->vendor)
            <div class="fw-semibold">{{ $store->vendor->name }}</div>
            <div class="text-muted small">Email: {{ $store->vendor->email ?? '—' }}</div>
            <div class="text-muted small">Phone: {{ $store->vendor->phone ?? '—' }}</div>
            <div class="alert alert-warning mt-2 mb-0 small">No Business record found.</div>
          @else
            <div class="text-muted">No business or vendor assigned.</div>
          @endif
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><strong>Products</strong> <span class="badge bg-light text-dark ms-2">{{ $productCount }}</span></div>
        <div class="card-body">
          @if($recentProducts->isEmpty())
            <div class="text-muted">No products yet.</div>
          @else
            <ul class="list-unstyled mb-0">
              @foreach($recentProducts as $p)
                <li class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                  <span>{{ $p->name }}</span>
                  <a href="{{ route('admin.products.edit', $p) }}" class="btn btn-xs btn-outline-primary">Edit</a>
                </li>
              @endforeach
            </ul>
          @endif
          <div class="mt-2"><a href="{{ route('admin.stores.products.index', $store) }}" class="small">View all products</a></div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><strong>Categories</strong> <span class="badge bg-light text-dark ms-2">{{ $categories->count() }}</span></div>
        <div class="card-body">
          @if($categories->isEmpty())
            <div class="text-muted">No categories yet.</div>
          @else
            <ul class="list-unstyled mb-0">
              @foreach($categories as $c)
                <li class="py-1 border-bottom small">{{ $c->name }}</li>
              @endforeach
            </ul>
          @endif
          <div class="mt-2"><a href="{{ route('admin.stores.categories.index', $store) }}" class="small">Manage categories</a></div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><strong>Packs</strong> <span class="badge bg-light text-dark ms-2">{{ $packs->count() }}</span></div>
        <div class="card-body">
          @if($packs->isEmpty())
            <div class="text-muted">No packs yet.</div>
          @else
            <ul class="list-unstyled mb-0">
              @foreach($packs as $pkg)
                <li class="py-1 border-bottom small">{{ $pkg->name }} <span class="text-muted">({{ number_format($pkg->amount,2) }})</span></li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </div>
  </div>

  <!-- Inline Modals: ensure presence in DOM for Bootstrap triggers -->
<!-- Edit Store Modal (copied from index and scoped for show page) -->
<div class="modal fade" id="editStoreModal" tabindex="-1" aria-labelledby="editStoreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editStoreLabel">Edit Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editStoreForm" action="{{ route('admin.stores.update', $store) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <input type="hidden" name="redirect_to" value="{{ route('admin.stores.show', $store) }}">
        <div class="modal-body">
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Business</label></div>
            <div class="col-md-9">
              <select name="business_id" id="editStoreBusiness" class="form-select" required>
                @foreach(($businesses ?? []) as $b)
                  <option value="{{ $b->id }}" {{ (int)($store->business_id) === (int)($b->id) ? 'selected' : '' }}>{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Name</label></div>
            <div class="col-md-9"><input type="text" name="name" id="editStoreName" class="form-control" value="{{ $store->name }}" required></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Slug</label></div>
            <div class="col-md-9"><input type="text" name="slug" id="editStoreSlug" class="form-control" value="{{ $store->slug }}" placeholder="auto-generated from name if left blank"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Description</label></div>
            <div class="col-md-9"><textarea name="description" id="editStoreDescription" class="form-control" rows="3" placeholder="Short description shown in listings">{{ $store->description }}</textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Logo</label></div>
            <div class="col-md-9">
              <div class="d-flex align-items-center">
                <div class="me-4 rounded-4 border" style="width: 160px; height: 80px; display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="editStoreLogoPreview" style="max-width:100%;max-height:100%;object-fit:contain;" src="{{ $store->logo_path ? asset('storage/'.$store->logo_path) : '' }}" alt="">
                </div>
                <div>
                  <input type="file" name="logo" class="form-control" accept=".png,.jpg,.jpeg,.webp" onchange="(e=>{const f=e.target.files[0];if(!f)return;const r=new FileReader();r.onload=ev=>{const img=document.getElementById('editStoreLogoPreview');if(img)img.src=ev.target.result;};r.readAsDataURL(f);})(event)">
                  <small class="text-muted">PNG, JPG, WEBP. Max 2MB.</small>
                </div>
              </div>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Support Email</label></div>
            <div class="col-md-9"><input type="email" name="support_email" id="editStoreSupportEmail" class="form-control" value="{{ $store->support_email }}"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Support Phone</label></div>
            <div class="col-md-9"><input type="text" name="support_phone" id="editStoreSupportPhone" class="form-control" value="{{ $store->support_phone }}"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Address</label></div>
            <div class="col-md-9"><textarea name="address" id="editStoreAddress" class="form-control" rows="3">{{ $store->address }}</textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Instagram URL</label></div>
            <div class="col-md-9"><input type="url" name="instagram_url" id="editStoreInstagramUrl" class="form-control" value="{{ $store->instagram_url }}" placeholder="https://instagram.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Facebook URL</label></div>
            <div class="col-md-9"><input type="url" name="facebook_url" id="editStoreFacebookUrl" class="form-control" value="{{ $store->facebook_url }}" placeholder="https://facebook.com/yourpage"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Twitter URL</label></div>
            <div class="col-md-9"><input type="url" name="twitter_url" id="editStoreTwitterUrl" class="form-control" value="{{ $store->twitter_url }}" placeholder="https://twitter.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">TikTok URL</label></div>
            <div class="col-md-9"><input type="url" name="tiktok_url" id="editStoreTiktokUrl" class="form-control" value="{{ $store->tiktok_url }}" placeholder="https://www.tiktok.com/@yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Ownership Type</label></div>
            <div class="col-md-9">
              <select name="ownership_type_id" id="editStoreOwnershipType" class="form-select">
                <option value="">Select...</option>
                @foreach(($ownershipTypes ?? []) as $o)
                  <option value="{{ $o->id }}" {{ (int)($store->ownership_type_id) === (int)($o->id) ? 'selected' : '' }}>{{ $o->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Business Type</label></div>
            <div class="col-md-9">
              <select name="business_type_id" id="editStoreBusinessType" class="form-select">
                <option value="">Select...</option>
                @foreach(($businessTypes ?? []) as $b)
                  <option value="{{ $b->id }}" {{ (int)($store->business_type_id) === (int)($b->id) ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Status</label></div>
            <div class="col-md-9">
              <select name="status" id="editStoreStatus" class="form-select">
                <option value="active" {{ $store->status==='active' ? 'selected' : '' }}>active</option>
                <option value="inactive" {{ $store->status==='inactive' ? 'selected' : '' }}>inactive</option>
                <option value="suspended" {{ $store->status==='suspended' ? 'selected' : '' }}>suspended</option>
                <option value="deleted" {{ $store->status==='deleted' ? 'selected' : '' }}>deleted</option>
              </select>
            </div>
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

<!-- Suspend Store Modal -->
<div class="modal fade" id="suspendStoreModal" tabindex="-1" aria-labelledby="suspendStoreLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="suspendStoreLabel">Suspend Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="suspendStoreForm" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Store</label>
            <input type="text" class="form-control" id="suspendStoreName" disabled>
          </div>
          <div class="mb-3">
            <label for="suspendStoreReason" class="form-label">Reason</label>
            <textarea class="form-control" id="suspendStoreReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Suspend</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Activate Store Modal -->
<div class="modal fade" id="activateStoreModal" tabindex="-1" aria-labelledby="activateStoreLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="activateStoreLabel">Activate Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="activateStoreForm" method="POST">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Store</label>
            <input type="text" class="form-control" id="activateStoreName" disabled>
          </div>
          <div class="mb-3">
            <label for="activateStoreReason" class="form-label">Reason / Notes</label>
            <textarea class="form-control" id="activateStoreReason" name="reason" rows="4" placeholder="Provide reason for activation" required></textarea>
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

</div>
@endsection
<script>
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('suspendStoreModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      var action = button.getAttribute('data-action');
      var storeName = button.getAttribute('data-store-name');
      var nameInput = document.getElementById('suspendStoreName');
      var form = document.getElementById('suspendStoreForm');
      if (nameInput) nameInput.value = storeName || '';
      if (form && action) { form.action = action; }
    });
  }
});

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('activateStoreModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      var action = button.getAttribute('data-action');
      var storeName = button.getAttribute('data-store-name');
      var nameInput = document.getElementById('activateStoreName');
      var form = document.getElementById('activateStoreForm');
      if (nameInput) nameInput.value = storeName || '';
      if (form && action) { form.action = action; }
    });
  }
});

// Edit Store modal population (same as index)
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('editStoreModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) return;
    var form = document.getElementById('editStoreForm');
    var action = button.getAttribute('data-action');
    if (form && action) form.action = action;

    var businessId = button.getAttribute('data-business-id') || '';
    var name = button.getAttribute('data-name') || '';
    var slug = button.getAttribute('data-slug') || '';
    var description = button.getAttribute('data-description') || '';
    var supportEmail = button.getAttribute('data-support-email') || '';
    var supportPhone = button.getAttribute('data-support-phone') || '';
    var address = button.getAttribute('data-address') || '';
    var instagramUrl = button.getAttribute('data-instagram-url') || '';
    var facebookUrl = button.getAttribute('data-facebook-url') || '';
    var twitterUrl = button.getAttribute('data-twitter-url') || '';
    var tiktokUrl = button.getAttribute('data-tiktok-url') || '';
    var ownershipTypeId = button.getAttribute('data-ownership-type-id') || '';
    var businessTypeId = button.getAttribute('data-business-type-id') || '';
    var status = (button.getAttribute('data-status') || '').toLowerCase();
    var logoUrl = button.getAttribute('data-logo-url') || '';

    document.getElementById('editStoreBusiness').value = businessId;
    document.getElementById('editStoreName').value = name;
    document.getElementById('editStoreSlug').value = slug;
    document.getElementById('editStoreDescription').value = description;
    document.getElementById('editStoreSupportEmail').value = supportEmail;
    document.getElementById('editStoreSupportPhone').value = supportPhone;
    document.getElementById('editStoreAddress').value = address;
    document.getElementById('editStoreInstagramUrl').value = instagramUrl;
    document.getElementById('editStoreFacebookUrl').value = facebookUrl;
    document.getElementById('editStoreTwitterUrl').value = twitterUrl;
    document.getElementById('editStoreTiktokUrl').value = tiktokUrl;
    document.getElementById('editStoreOwnershipType').value = ownershipTypeId;
    document.getElementById('editStoreBusinessType').value = businessTypeId;
    var statusSelect = document.getElementById('editStoreStatus');
    if (statusSelect) Array.from(statusSelect.options).forEach(function(opt){ opt.selected = (opt.value.toLowerCase() === status); });
    var logoPreview = document.getElementById('editStoreLogoPreview');
    if (logoPreview) logoPreview.src = logoUrl;
  });
});
</script>
