@extends('admin.layout')
@section('subtitle', 'Stores')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Stores</h6>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#filterStoresModal">Filter</button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createStoreModal">Add Store</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Name</th>
                                <th>Business</th>
                                <th>Owner</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Shop Link</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($stores as $store)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                          @if($store->logo_path)
                                              <img src="{{ asset('storage/'.$store->logo_path) }}" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:4px;border:1px solid #eee;">
                                          @endif
                                          <span>{{ $store->name }}</span>
                                          @if(isset($mainStoreId) && (int)$mainStoreId === (int)$store->id)
                                              <span class="badge bg-primary" style="line-height:1;">Main</span>
                                          @endif
                                        </div>
                                    </td>
                                    <td>
                                        @if($store->business)
                                            <a href="{{ route('admin.vendors.show', $store->vendor) }}" class="text-dark text-decoration-none">{{ $store->business->name }}</a>
                                            <div class="small text-muted font-monospace">{{ $store->business->business_code }}</div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $store->vendor?->name ?? '—' }}</td>
                                    <td>{{ $store->businessType?->name ?? '—' }}</td>
                                    <td>
                                        @php($storeBadge = $storeStatusBadgeData[strtolower($store->status)] ?? null)
                                        <span class="badge {{ $storeBadge['class'] ?? 'bg-secondary' }}">
                                            {{ $storeBadge['label'] ?? ucfirst($store->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if(!empty($store->slug))
                                            <a href="{{ route('home.store.products.index', ['store_subdomain' => $store->slug]) }}" target="_blank">
                                                view shop
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown d-inline-block">
                                            <button class="btn btn-sm border-0 bg-transparent text-dark" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Store actions">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="{{ route('admin.stores.show', $store) }}">
                                                        <i class="fa fa-eye me-2 text-muted"></i>View
                                                    </a>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#editStoreModal"
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
                                                        <i class="fa fa-pen me-2 text-muted"></i>Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    @if(strtolower($store->status) === 'suspended' || strtolower($store->status) === 'inactive' || strtolower($store->status) === 'pending')
                                                        <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#activateStoreModal"
                                                                data-action="{{ route('admin.stores.activate', $store) }}"
                                                                data-store-name="{{ $store->name }}">
                                                            <i class="fa fa-check me-2 text-muted"></i>Activate
                                                        </button>
                                                    @else
                                                        <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#suspendStoreModal"
                                                                data-action="{{ route('admin.stores.suspend', $store) }}"
                                                                data-store-name="{{ $store->name }}">
                                                            <i class="fa fa-ban me-2 text-muted"></i>Suspend
                                                        </button>
                                                    @endif
                                                </li>
                                                <li>
                                                    <button class="dropdown-item d-flex align-items-center" type="button" data-bs-toggle="modal" data-bs-target="#deleteStoreModal"
                                                            data-action="{{ route('admin.stores.destroy', $store) }}"
                                                            data-store-name="{{ $store->name }}">
                                                        <i class="fa fa-trash me-2 text-muted"></i>Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted">No stores yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">{{ $stores->links() }}</div>
            </div>
        </div>
    </div>
        </div>
    </div>
</div>

<!-- Create Store Modal -->
<div class="modal fade" id="createStoreModal" tabindex="-1" aria-labelledby="createStoreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createStoreLabel">Add Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="{{ route('admin.stores.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="modal-body">
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Business</label></div>
            <div class="col-md-9">
              <select name="business_id" class="form-select" required>
                <option value="">Select a business...</option>
                @foreach(($businesses ?? []) as $b)
                  <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Name</label></div>
            <div class="col-md-9"><input type="text" name="name" class="form-control" required></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Slug</label></div>
            <div class="col-md-9"><input type="text" name="slug" class="form-control" placeholder="auto-generated from name if left blank"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Description</label></div>
            <div class="col-md-9"><textarea name="description" class="form-control" rows="3" placeholder="Short description shown in listings"></textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Logo</label></div>
            <div class="col-md-9">
              <div class="d-flex align-items-center">
                <div class="me-4 rounded-4 border" style="width: 160px; height: 80px; display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="createStoreLogoPreview" style="max-width:100%;max-height:100%;object-fit:contain;" alt="">
                </div>
                <div>
                  <input type="file" name="logo" class="form-control" accept=".png,.jpg,.jpeg,.webp" onchange="(e=>{const f=e.target.files[0];if(!f)return;const r=new FileReader();r.onload=ev=>{const img=document.getElementById('createStoreLogoPreview');if(img)img.src=ev.target.result;};r.readAsDataURL(f);})(event)">
                  <small class="text-muted">PNG, JPG, WEBP. Max 2MB.</small>
                </div>
              </div>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Support Email</label></div>
            <div class="col-md-9"><input type="email" name="support_email" class="form-control"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Support Phone</label></div>
            <div class="col-md-9"><input type="text" name="support_phone" class="form-control"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Address</label></div>
            <div class="col-md-9"><textarea name="address" class="form-control" rows="3"></textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Instagram URL</label></div>
            <div class="col-md-9"><input type="url" name="instagram_url" class="form-control" placeholder="https://instagram.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Facebook URL</label></div>
            <div class="col-md-9"><input type="url" name="facebook_url" class="form-control" placeholder="https://facebook.com/yourpage"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Twitter URL</label></div>
            <div class="col-md-9"><input type="url" name="twitter_url" class="form-control" placeholder="https://twitter.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">TikTok URL</label></div>
            <div class="col-md-9"><input type="url" name="tiktok_url" class="form-control" placeholder="https://www.tiktok.com/@yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Ownership Type</label></div>
            <div class="col-md-9">
              <select name="ownership_type_id" class="form-select">
                <option value="">Select...</option>
                @foreach(($ownershipTypes ?? []) as $o)
                  <option value="{{ $o->id }}">{{ $o->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Business Type</label></div>
            <div class="col-md-9">
              <select name="business_type_id" class="form-select">
                <option value="">Select...</option>
                @foreach(($businessTypes ?? []) as $b)
                  <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Status</label></div>
            <div class="col-md-9">
              <select name="status" class="form-select">
                <option value="active">active</option>
                <option value="inactive">inactive</option>
              </select>
            </div>
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

<!-- Edit Store Modal -->
<div class="modal fade" id="editStoreModal" tabindex="-1" aria-labelledby="editStoreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editStoreLabel">Edit Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editStoreForm" action="#" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="modal-body">
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Business</label></div>
            <div class="col-md-9">
              <select name="business_id" id="editStoreBusiness" class="form-select" required>
                @foreach(($businesses ?? []) as $b)
                  <option value="{{ $b->id }}" {{ $store->business_id == $b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Name</label></div>
            <div class="col-md-9"><input type="text" name="name" id="editStoreName" class="form-control" required></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Slug</label></div>
            <div class="col-md-9"><input type="text" name="slug" id="editStoreSlug" class="form-control" placeholder="auto-generated from name if left blank"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Description</label></div>
            <div class="col-md-9"><textarea name="description" id="editStoreDescription" class="form-control" rows="3" placeholder="Short description shown in listings"></textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Logo</label></div>
            <div class="col-md-9">
              <div class="d-flex align-items-center">
                <div class="me-4 rounded-4 border" style="width: 160px; height: 80px; display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="editStoreLogoPreview" style="max-width:100%;max-height:100%;object-fit:contain;" alt="">
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
            <div class="col-md-9"><input type="email" name="support_email" id="editStoreSupportEmail" class="form-control"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Support Phone</label></div>
            <div class="col-md-9"><input type="text" name="support_phone" id="editStoreSupportPhone" class="form-control"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Address</label></div>
            <div class="col-md-9"><textarea name="address" id="editStoreAddress" class="form-control" rows="3"></textarea></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Instagram URL</label></div>
            <div class="col-md-9"><input type="url" name="instagram_url" id="editStoreInstagramUrl" class="form-control" placeholder="https://instagram.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Facebook URL</label></div>
            <div class="col-md-9"><input type="url" name="facebook_url" id="editStoreFacebookUrl" class="form-control" placeholder="https://facebook.com/yourpage"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Twitter URL</label></div>
            <div class="col-md-9"><input type="url" name="twitter_url" id="editStoreTwitterUrl" class="form-control" placeholder="https://twitter.com/yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">TikTok URL</label></div>
            <div class="col-md-9"><input type="url" name="tiktok_url" id="editStoreTiktokUrl" class="form-control" placeholder="https://www.tiktok.com/@yourhandle"></div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Ownership Type</label></div>
            <div class="col-md-9">
              <select name="ownership_type_id" id="editStoreOwnershipType" class="form-select">
                <option value="">Select...</option>
                @foreach(($ownershipTypes ?? []) as $o)
                  <option value="{{ $o->id }}">{{ $o->name }}</option>
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
                  <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row mb-3 align-items-center">
            <div class="col-md-3"><label class="form-label mb-md-0">Status</label></div>
            <div class="col-md-9">
              <select name="status" id="editStoreStatus" class="form-select">
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="suspended">suspended</option>
                <option value="deleted">deleted</option>
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

<!-- Delete Store Modal -->
<div class="modal fade" id="deleteStoreModal" tabindex="-1" aria-labelledby="deleteStoreLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteStoreLabel">Delete Store</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="deleteStoreForm" method="POST">
        @csrf
        @method('DELETE')
        <div class="modal-body">
          <div class="alert alert-warning">
            <strong>⚠️ Warning:</strong> This action will mark the store as deleted. The store will no longer be accessible.
          </div>
          <div class="mb-3">
            <label class="form-label">Store</label>
            <input type="text" class="form-control" id="deleteStoreName" disabled>
          </div>
          <p class="text-muted small">Note: Deletion will only proceed if all orders and transactions associated with this store are completed.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete Store</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Filter Stores Modal -->
<div class="modal fade" id="filterStoresModal" tabindex="-1" aria-labelledby="filterStoresLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filterStoresLabel">Filter Stores</h5>
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
                <option value="inactive" @selected(($status ?? '')==='inactive')>Inactive</option>
                <option value="suspended" @selected(($status ?? '')==='suspended')>Suspended</option>
                <option value="deleted" @selected(($status ?? '')==='deleted')>Deleted</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Search</label>
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Name, store ID or vendor name">
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
          <a href="{{ route('admin.stores.index') }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('suspendStoreModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var storeName = button.getAttribute('data-store-name');
    var nameInput = document.getElementById('suspendStoreName');
    var form = document.getElementById('suspendStoreForm');
    if (nameInput) nameInput.value = storeName || '';
    if (form && action) {
      form.action = action;
    }
  });
});

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('activateStoreModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var storeName = button.getAttribute('data-store-name');
    var nameInput = document.getElementById('activateStoreName');
    var form = document.getElementById('activateStoreForm');
    if (nameInput) nameInput.value = storeName || '';
    if (form && action) {
      form.action = action;
    }
  });
});

document.addEventListener('DOMContentLoaded', function() {
  var modal = document.getElementById('deleteStoreModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var action = button.getAttribute('data-action');
    var storeName = button.getAttribute('data-store-name');
    var nameInput = document.getElementById('deleteStoreName');
    var form = document.getElementById('deleteStoreForm');
    if (nameInput) nameInput.value = storeName || '';
    if (form && action) {
      form.action = action;
    }
  });
});

// Edit Store modal population
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
@endsection
