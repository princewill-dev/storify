@extends('vendors.layout')
@section('subtitle', $store->name)

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0">Store: {{ $store->name }}</h6>
    <div class="dropdown">
      <button class="btn btn-primary btn-sm dropdown-toggle fw-bold px-3 rounded-pill shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fi fi-rr-settings me-1"></i> Actions
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="border-radius: 12px; min-width: 200px;">
        <li><h6 class="dropdown-header text-uppercase fs-xs fw-black px-3" style="color: #666; letter-spacing: 0.5px;">Store Management</h6></li>
        <li>
          <a class="dropdown-item d-flex align-items-center py-2 px-3" href="javascript:void(0)" 
             data-bs-toggle="modal" data-bs-target="#editStoreModal"
             data-action="{{ route('vendor.stores.update', $store) }}"
             data-vendor-id="{{ $store->vendor_id }}"
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
            <i class="fi fi-rr-edit me-2 text-primary"></i> <span>Edit Store</span>
          </a>
        </li>
        @if(strtolower($store->status) === 'suspended')
          <li>
            <a class="dropdown-item d-flex align-items-center py-2 px-3 text-success" href="javascript:void(0)" 
               data-bs-toggle="modal" data-bs-target="#activateStoreModal" 
               data-action="{{ route('vendor.stores.activate', $store) }}" 
               data-store-name="{{ $store->name }}">
              <i class="fi fi-rr-play me-2"></i> <span>Activate Store</span>
            </a>
          </li>
        @else
          <li>
            <a class="dropdown-item d-flex align-items-center py-2 px-3 text-warning" href="javascript:void(0)" 
               data-bs-toggle="modal" data-bs-target="#suspendStoreModal" 
               data-action="{{ route('vendor.stores.suspend', $store) }}" 
               data-store-name="{{ $store->name }}">
              <i class="fi fi-rr-pause me-2"></i> <span>Suspend Store</span>
            </a>
          </li>
        @endif
        
        <li><hr class="dropdown-divider mx-2"></li>
        <li><h6 class="dropdown-header text-uppercase fs-xs fw-black px-3" style="color: #666; letter-spacing: 0.5px;">Quick Links</h6></li>
        <li>
          <a class="dropdown-item d-flex align-items-center py-2 px-3" href="{{ route('vendor.products.create', ['vendor' => $vendor]) }}?store_id={{ $store->store_id }}">
            <i class="fi fi-rr-plus me-2 text-info"></i> <span>Add Product</span>
          </a>
        </li>
        <li>
          <a class="dropdown-item d-flex align-items-center py-2 px-3" href="{{ route('vendor.categories.create', ['vendor' => $vendor]) }}?store_id={{ $store->store_id }}">
            <i class="fi fi-rr-apps-add me-2 text-info"></i> <span>Add Category</span>
          </a>
        </li>
        <li>
          <a class="dropdown-item d-flex align-items-center py-2 px-3" href="{{ route('vendor.products.index', ['vendor' => $vendor, 'store_id' => $store->store_id]) }}">
            <i class="fi fi-rr-boxes me-2 text-secondary"></i> <span>Manage Products</span>
          </a>
        </li>
        <li>
          <a class="dropdown-item d-flex align-items-center py-2 px-3" href="{{ route('vendor.categories.index', ['vendor' => $vendor, 'store_id' => $store->store_id]) }}">
            <i class="fi fi-rr-list me-2 text-secondary"></i> <span>Manage Categories</span>
          </a>
        </li>
      </ul>
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
            <div class="col-md-6">
              <div class="text-muted small">Ownership</div>
              <div>{{ $store->ownershipType?->name ?? '—' }}</div>
            </div>
            <div class="col-md-6">
              <div class="text-muted small">Business</div>
              <div>{{ $store->businessType?->name ?? '—' }}</div>
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

    <!-- Bank Accounts Section -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Bank Accounts</strong>
            <button class="btn btn-xs btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addBankModal">
                <i class="fi fi-rr-plus me-1"></i> Add Bank
            </button>
        </div>
        <div class="card-body">
          @if($store->banks->isEmpty())
            <div class="text-muted text-center py-3">
                <i class="fi fi-rr-bank fs-2 d-block mb-2 opacity-25"></i>
                No bank accounts added yet.
            </div>
          @else
            <div class="table-responsive">
                <table class="table table-sm table-nowrap mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Bank</th>
                            <th>Account Details</th>
                            <th class="text-center">Primary</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($store->banks as $bank)
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ $bank->bank_name }}</div>
                                    <div class="text-muted small">Code: {{ $bank->bank_code }}</div>
                                </td>
                                <td>
                                    <div class="fw-medium">{{ $bank->account_name }}</div>
                                    <div class="text-muted small"><code>{{ $bank->masked_account_number }}</code></div>
                                </td>
                                <td class="text-center">
                                    @if($bank->is_primary)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2">Primary</span>
                                    @else
                                        <form action="{{ route('vendor.stores.banks.primary', ['vendor' => $vendor, 'store' => $store, 'bank' => $bank]) }}" method="POST" style="display:inline;">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-link p-0 text-muted small" title="Set as Primary">Make Primary</button>
                                        </form>
                                    @endif
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-icon btn-light border" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editBankModal"
                                                data-bank-id="{{ $bank->id }}"
                                                data-bank-name="{{ $bank->bank_name }}"
                                                data-bank-code="{{ $bank->bank_code }}"
                                                data-account-number="{{ $bank->account_number }}"
                                                data-account-name="{{ $bank->account_name }}"
                                                data-is-primary="{{ $bank->is_primary ? '1' : '0' }}">
                                            <i class="fi fi-rr-edit"></i>
                                        </button>
                                        @if(!$bank->is_primary)
                                            <button class="btn btn-sm btn-icon btn-light border text-danger ms-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteBankModal"
                                                    data-bank-id="{{ $bank->id }}"
                                                    data-bank-name="{{ $bank->bank_name }}">
                                                <i class="fi fi-rr-trash"></i>
                                            </button>
                                        @endif
                                    </div>
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
                  <a href="{{ route('vendor.products.edit', ['vendor' => $vendor, 'product' => $p]) }}" class="btn btn-xs btn-outline-primary">Edit</a>
                </li>
              @endforeach
            </ul>
          @endif
          <div class="mt-2"><a href="{{ route('vendor.products.index', ['vendor' => $vendor, 'store_id' => $store->store_id]) }}" class="small">View all products</a></div>
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
          <div class="mt-2"><a href="{{ route('vendor.categories.index', ['vendor' => $vendor, 'store_id' => $store->store_id]) }}" class="small">Manage categories</a></div>
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
      <form id="editStoreForm" action="{{ route('vendor.stores.update', $store) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <input type="hidden" name="redirect_to" value="{{ route('vendor.stores.show', ['vendor' => $vendor, 'store' => $store]) }}">
        <div class="modal-body">
          <div class="row mb-3 align-items-center d-none">
            <div class="col-md-3"><label class="form-label mb-md-0">Vendor</label></div>
            <div class="col-md-9">
              <input type="hidden" name="vendor_id" id="editStoreVendor" value="{{ $vendor->id }}">
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
              <select name="status" id="editStoreStatus" class="form-select" disabled>
                <option value="active" {{ $store->status==='active' ? 'selected' : '' }}>active</option>
                <option value="inactive" {{ $store->status==='inactive' ? 'selected' : '' }}>inactive</option>
                <option value="suspended" {{ $store->status==='suspended' ? 'selected' : '' }}>suspended</option>
                <option value="deleted" {{ $store->status==='deleted' ? 'selected' : '' }}>deleted</option>
              </select>
              <small class="text-muted">Status can only be changed via the Actions menu.</small>
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

<!-- Add Bank Modal -->
<div class="modal fade" id="addBankModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Bank Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="{{ route('vendor.stores.banks.store', ['vendor' => $vendor, 'store' => $store]) }}" method="POST" id="addBankForm">
        @csrf
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Bank Name</label>
            <select name="bank_code" class="form-select bank-selector" required>
              <option value="">Loading banks...</option>
            </select>
            <input type="hidden" name="bank_name" class="bank-name-hidden">
          </div>
          <div class="mb-3">
            <label class="form-label">Account Number</label>
            <div class="input-group">
                <input type="text" name="account_number" class="form-control" maxlength="10" required>
                <button type="button" class="btn btn-outline-secondary btn-validate-bank">Verify</button>
            </div>
            <div class="bank-validation-feedback mt-1 small"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Account Name</label>
            <input type="text" name="account_name" class="form-control" readonly required>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_primary" id="addBankPrimary">
            <label class="form-check-label" for="addBankPrimary">Set as Primary</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="addBankSubmit" disabled>Add Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Bank Modal -->
<div class="modal fade" id="editBankModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Bank Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="editBankForm">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="mb-3 text-muted small">
            <i class="fi fi-rr-info me-1"></i> Bank and account number cannot be changed for security. Delete and re-add if incorrect.
          </div>
          <div class="mb-3">
            <label class="form-label">Bank Name</label>
            <input type="text" id="editBankName" class="form-control bg-light" readonly>
            <input type="hidden" name="bank_name" id="editBankNameHidden">
            <input type="hidden" name="bank_code" id="editBankCode">
          </div>
          <div class="mb-3">
            <label class="form-label">Account Number</label>
            <input type="text" name="account_number" id="editAccountNumber" class="form-control bg-light" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Account Name</label>
            <input type="text" name="account_name" id="editAccountName" class="form-control bg-light" readonly>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_primary" id="editBankPrimary">
            <label class="form-check-label" for="editBankPrimary">Set as Primary</label>
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

<!-- Delete Bank Modal -->
<div class="modal fade" id="deleteBankModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Delete Bank Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="deleteBankForm">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <p>Are you sure you want to delete the bank account for <strong id="deleteBankDisplay"></strong>?</p>
          <p class="text-danger small"><i class="fi fi-rr-exclamation me-1"></i> This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
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

    var vendorId = button.getAttribute('data-vendor-id') || '';
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

    // Safeguard elements
    const vIdEl = document.getElementById('editStoreVendor');
    if(vIdEl) vIdEl.value = vendorId;
    
    const nameEl = document.getElementById('editStoreName');
    if(nameEl) nameEl.value = name;
    
    const slugEl = document.getElementById('editStoreSlug');
    if(slugEl) slugEl.value = slug;
    
    const descEl = document.getElementById('editStoreDescription');
    if(descEl) descEl.value = description;
    
    const sEmailEl = document.getElementById('editStoreSupportEmail');
    if(sEmailEl) sEmailEl.value = supportEmail;
    
    const sPhoneEl = document.getElementById('editStoreSupportPhone');
    if(sPhoneEl) sPhoneEl.value = supportPhone;
    
    const addrEl = document.getElementById('editStoreAddress');
    if(addrEl) addrEl.value = address;
    
    const instaEl = document.getElementById('editStoreInstagramUrl');
    if(instaEl) instaEl.value = instagramUrl;
    
    const fbEl = document.getElementById('editStoreFacebookUrl');
    if(fbEl) fbEl.value = facebookUrl;
    
    const twEl = document.getElementById('editStoreTwitterUrl');
    if(twEl) twEl.value = twitterUrl;
    
    const tkEl = document.getElementById('editStoreTiktokUrl');
    if(tkEl) tkEl.value = tiktokUrl;
    
    const ownEl = document.getElementById('editStoreOwnershipType');
    if(ownEl) ownEl.value = ownershipTypeId;
    
    const bizEl = document.getElementById('editStoreBusinessType');
    if(bizEl) bizEl.value = businessTypeId;
    
    var statusSelect = document.getElementById('editStoreStatus');
    if (statusSelect) Array.from(statusSelect.options).forEach(function(opt){ opt.selected = (opt.value.toLowerCase() === status); });
    var logoPreview = document.getElementById('editStoreLogoPreview');
    if (logoPreview) logoPreview.src = logoUrl;
  });
});

// Bank Management Scripts
document.addEventListener('DOMContentLoaded', function() {
    // 1. Fetch Banks
    fetch("{{ route('vendor.kyc.store.get-banks', ['vendor' => $vendor]) }}")
        .then(response => response.json())
        .then(data => {
            const selectors = document.querySelectorAll('.bank-selector');
            selectors.forEach(select => {
                select.innerHTML = '<option value="">Select Bank</option>';
                if (data.status && data.data) {
                    data.data.forEach(bank => {
                        const option = document.createElement('option');
                        option.value = bank.code;
                        option.textContent = bank.name;
                        select.appendChild(option);
                    });
                }
            });
        });

    // Handle Bank Name synchronization
    document.querySelectorAll('.bank-selector').forEach(select => {
        select.addEventListener('change', function() {
            const nameHidden = this.closest('form').querySelector('.bank-name-hidden');
            if (nameHidden) {
                nameHidden.value = this.options[this.selectedIndex].text;
            }
        });
    });

    // Bank Account Validation Logic
    document.querySelectorAll('.btn-validate-bank').forEach(btn => {
        btn.addEventListener('click', function() {
            const form = this.closest('form');
            const accountNumber = form.querySelector('input[name="account_number"]').value;
            const bankCode = form.querySelector('select[name="bank_code"]').value;
            const feedback = form.querySelector('.bank-validation-feedback');
            const accountNameInput = form.querySelector('input[name="account_name"]');
            const submitBtn = form.querySelector('button[type="submit"]');

            if (accountNumber.length !== 10 || !bankCode) {
                alert('Please enter a valid 10-digit account number and select a bank.');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            feedback.innerHTML = '<span class="text-muted">Verifying...</span>';

            fetch("{{ route('vendor.kyc.store.validate-bank', ['vendor' => $vendor]) }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ account_number: accountNumber, bank_code: bankCode })
            })
            .then(response => response.json())
            .then(data => {
                this.disabled = false;
                this.innerHTML = 'Verify';
                
                if (data.status && data.data) {
                    accountNameInput.value = data.data.account_name;
                    feedback.innerHTML = '<span class="text-success"><i class="fi fi-rr-check"></i> Account verified</span>';
                    submitBtn.disabled = false;
                } else {
                    accountNameInput.value = '';
                    feedback.innerHTML = '<span class="text-danger"><i class="fi fi-rr-cross"></i> ' + (data.message || 'Verification failed') + '</span>';
                    submitBtn.disabled = true;
                }
            })
            .catch(err => {
                this.disabled = false;
                this.innerHTML = 'Verify';
                feedback.innerHTML = '<span class="text-danger">Error during verification.</span>';
                submitBtn.disabled = true;
            });
        });
    });

    // 2. Edit Modal Population
    var editModal = document.getElementById('editBankModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-bank-id');
            var bankName = button.getAttribute('data-bank-name');
            var bankCode = button.getAttribute('data-bank-code');
            var accountNumber = button.getAttribute('data-account-number');
            var accountName = button.getAttribute('data-account-name');
            var isPrimary = button.getAttribute('data-is-primary') === '1';

            var form = document.getElementById('editBankForm');
            var url = "{{ route('vendor.stores.banks.update', ['vendor' => $vendor, 'store' => $store, 'bank' => ':id']) }}".replace(':id', id);
            form.action = url;

            document.getElementById('editBankName').value = bankName;
            document.getElementById('editBankNameHidden').value = bankName;
            document.getElementById('editBankCode').value = bankCode;
            document.getElementById('editAccountNumber').value = accountNumber;
            document.getElementById('editAccountName').value = accountName;
            document.getElementById('editBankPrimary').checked = isPrimary;
        });
    }

    // 3. Delete Modal Population
    var deleteModal = document.getElementById('deleteBankModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-bank-id');
            var bankName = button.getAttribute('data-bank-name');

            var form = document.getElementById('deleteBankForm');
            var url = "{{ route('vendor.stores.banks.destroy', ['vendor' => $vendor, 'store' => $store, 'bank' => ':id']) }}".replace(':id', id);
            form.action = url;

            document.getElementById('deleteBankDisplay').textContent = bankName;
        });
    }
});
</script>
