@extends('vendors.layout')
@section('subtitle', 'Stores')

@section('content')
<div class="row g-4">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
          <h5 class="mb-1">My Stores</h5>
          <small class="text-muted">Manage the stores linked to your vendor account.</small>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#filterStoresModal">
            <i class="fi fi-rr-filter"></i>
            <span class="ms-1">Filters</span>
          </button>
          @if($canCreate)
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createStoreModal">
              <i class="fi fi-rr-add"></i>
              <span class="ms-1">Create new store</span>
            </button>
          @else
            @if(!$vendor->is_verified || !in_array($vendor->kycApplication?->status, ['submitted', 'approved']))
                <button class="btn btn-secondary btn-sm" type="button" disabled title="Complete verification/KYC to add more stores">
                  <i class="fi fi-rr-lock"></i>
                  <span class="ms-1">Verification Required</span>
                </button>
            @else
                <button class="btn btn-secondary btn-sm" type="button" disabled title="You have reached the maximum number of stores allowed.">
                  <i class="fi fi-rr-ban"></i>
                  <span class="ms-1">Store Limit Reached</span>
                </button>
            @endif
          @endif
        </div>
      </div>
      <div class="card-body">
        @if($stores->isEmpty())
          <div class="text-center py-5">
            <div class="mb-3">
              <i class="fi fi-rr-shop text-muted" style="font-size: 48px;"></i>
            </div>
            <p class="text-muted mb-3">You don't have any stores yet. Click "Add Store" to create your storefront.</p>
            @if($canCreate)
              <a href="{{ route('vendor.stores.create', ['vendor' => $vendor]) }}" class="btn btn-primary">Create Store</a>
            @elseif(!$vendor->is_verified || !in_array($vendor->kycApplication?->status, ['submitted', 'approved']))
              <button class="btn btn-secondary" disabled>Verification Required</button>
            @else
               <button class="btn btn-secondary" disabled>Store Limit Reached</button>
            @endif
          </div>
        @else
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th style="width: 60px;">#</th>
                  <th>Store</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Shop Link</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                @foreach($stores as $store)
                  @php(
                    $storeUrl = ($store->slug && app('router')->has('home.store.products.index'))
                      ? route('home.store.products.index', ['store_subdomain' => $store->slug])
                      : null
                  )
                  <tr>
                    <td>{{ $stores->firstItem() + $loop->index }}</td>
                    <td>
                      <div class="d-flex align-items-center gap-3">
                        <div class="rounded border bg-light" style="width:64px;height:64px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                          @if($store->logo_path)
                            <img src="{{ asset('storage/'.$store->logo_path) }}" alt="{{ $store->name }} logo" class="img-fluid" style="max-height:100%;object-fit:contain;">
                          @else
                            <span class="text-uppercase fw-semibold text-muted">{{ Str::limit($store->name, 2, '') }}</span>
                          @endif
                        </div>
                        <div>
                          <div class="fw-semibold">{{ $store->name }}</div>
                          <div class="text-muted small">Store ID: {{ $store->store_id ?? '—' }}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      @php(
                        $statusClass = [
                          'active' => 'badge bg-success-subtle text-success',
                          'inactive' => 'badge bg-warning-subtle text-warning',
                          'suspended' => 'badge bg-danger-subtle text-danger',
                          'deleted' => 'badge bg-dark-subtle text-dark'
                        ][$store->status] ?? 'badge bg-secondary-subtle text-secondary'
                      )
                      <span class="{{ $statusClass }} text-capitalize">{{ $store->status ?? 'unknown' }}</span>
                    </td>
                    <td>
                      <div class="d-flex flex-column">
                        <span>{{ optional($store->created_at)->format('d M, Y') }}</span>
                        <small class="text-muted">{{ optional($store->created_at)->format('h:i A') }}</small>
                      </div>
                    </td>
                    <td>
                      @if($storeUrl)
                        <div class="d-flex align-items-center gap-2">
                          <a href="{{ $storeUrl }}" target="_blank" rel="noopener" class="text-primary small">{{ $storeUrl }}</a>
                          <button class="btn btn-link btn-sm px-1 copy-store-url" type="button" data-url="{{ $storeUrl }}" title="Copy link">
                            <i class="fi fi-rr-copy"></i>
                          </button>
                        </div>
                      @else
                        <span class="text-muted small">Publish your store to get a link</span>
                      @endif
                    </td>
                    <td class="text-end">
                      <div class="btn-group">
                        <a href="{{ route('vendor.stores.show', ['vendor' => $vendor, 'store' => $store]) }}" class="btn btn-outline-secondary btn-sm">View</a>
                        <button
                          type="button"
                          class="btn btn-outline-primary btn-sm"
                          data-bs-toggle="modal"
                          data-bs-target="#editStoreModal"
                          data-action="{{ route('vendor.stores.update', $store) }}"
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
                          data-logo-url="{{ $store->logo_path ? asset('storage/'.$store->logo_path) : '' }}"
                        >
                          Edit
                        </button>
                      </div>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3">
            <small class="text-muted">Showing {{ $stores->firstItem() }}-{{ $stores->lastItem() }} of {{ $stores->total() }} store(s)</small>
            {{ $stores->links('general.pagination.only-links') }}
          </div>
        @endif
      </div>
    </div>
  </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterStoresModal" tabindex="-1" aria-labelledby="filterStoresLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="GET" action="{{ route('vendor.stores.index') }}">
        <div class="modal-header">
          <h5 class="modal-title" id="filterStoresLabel">Filter stores</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-4">
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach(['active','inactive','suspended','deleted'] as $option)
                  <option value="{{ $option }}" @selected(($status ?? '') === $option)>{{ ucfirst($option) }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Search</label>
              <input type="text" name="q" value="{{ $q ?? '' }}" class="form-control" placeholder="Search by store name or ID">
            </div>
            <div class="col-md-6">
              <label class="form-label">From</label>
              <input type="date" name="from" value="{{ $from ?? '' }}" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">To</label>
              <input type="date" name="to" value="{{ $to ?? '' }}" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="{{ route('vendor.stores.index') }}" class="btn btn-light">Reset</a>
          <button type="submit" class="btn btn-primary">Apply filters</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Store Modal -->
<div class="modal fade" id="editStoreModal" tabindex="-1" aria-labelledby="editStoreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="editStoreForm" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <input type="hidden" name="redirect_to" value="{{ request()->fullUrl() }}">
        <div class="modal-header">
          <h5 class="modal-title" id="editStoreLabel">Edit store</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Store name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="editStoreName" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Slug</label>
              <input type="text" name="slug" id="editStoreSlug" class="form-control" placeholder="Auto-generated if left blank">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="editStoreDescription" class="form-control" rows="3" placeholder="Short description shown to customers"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Logo</label>
              <div class="d-flex align-items-center gap-3">
                <div class="rounded border bg-light" style="width:160px;height:80px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="editStoreLogoPreview" src="" alt="Store logo preview" style="max-width:100%;max-height:100%;object-fit:contain;">
                </div>
                <div class="flex-grow-1">
                  <input type="file" name="logo" class="form-control" accept="image/*" onchange="window.handleLogoPreview(event, 'editStoreLogoPreview')">
                  <small class="text-muted">PNG, JPG, or WEBP. Max 2MB.</small>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Support email</label>
              <input type="email" name="support_email" id="editStoreSupportEmail" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Support phone</label>
              <input type="text" name="support_phone" id="editStoreSupportPhone" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" id="editStoreAddress" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Instagram URL</label>
              <input type="url" name="instagram_url" id="editStoreInstagramUrl" class="form-control" placeholder="https://instagram.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">Facebook URL</label>
              <input type="url" name="facebook_url" id="editStoreFacebookUrl" class="form-control" placeholder="https://facebook.com/yourpage">
            </div>
            <div class="col-md-6">
              <label class="form-label">Twitter URL</label>
              <input type="url" name="twitter_url" id="editStoreTwitterUrl" class="form-control" placeholder="https://twitter.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">TikTok URL</label>
              <input type="url" name="tiktok_url" id="editStoreTiktokUrl" class="form-control" placeholder="https://www.tiktok.com/@yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">Ownership type</label>
              <select name="ownership_type_id" id="editStoreOwnershipType" class="form-select">
                <option value="">Select...</option>
                @foreach($ownershipTypes as $type)
                  <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Business type</label>
              <select name="business_type_id" id="editStoreBusinessType" class="form-select">
                <option value="">Select...</option>
                @foreach($businessTypes as $type)
                  <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
              </select>
            </div>
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

<!-- Create Store Modal -->
<div class="modal fade" id="createStoreModal" tabindex="-1" aria-labelledby="createStoreLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createStoreLabel">Add Store</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="createStoreForm" action="{{ route('vendor.stores.store', ['vendor' => $vendor]) }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label">Store name <span class="text-danger">*</span></label>
              <input type="text" id="createStoreName" name="name" class="form-control" placeholder="eg: Swift Essentials" required>
              
              <!-- Slug availability feedback -->
              <div id="createStoreSlugFeedback" class="mt-2" style="display: none;">
                  <small id="createStoreSlugStatus" class="d-flex align-items-center gap-2"></small>
              </div>
              <input type="hidden" id="createStoreSlug" name="slug" value="{{ old('slug') }}">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Short description shown to shoppers"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Logo</label>
              <div class="d-flex align-items-center gap-3">
                <div class="rounded border bg-light" style="width:160px;height:80px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="createStoreLogoPreview" alt="Store logo preview" style="max-width:100%;max-height:100%;object-fit:contain;">
                </div>
                <div class="flex-grow-1">
                  <input type="file" name="logo" class="form-control" accept="image/*" onchange="window.handleLogoPreview(event, 'createStoreLogoPreview')">
                  <small class="text-muted">PNG, JPG, or WEBP up to 2MB.</small>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Support email</label>
              <input type="email" name="support_email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Support phone</label>
              <input type="text" name="support_phone" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Instagram URL</label>
              <input type="url" name="instagram_url" class="form-control" placeholder="https://instagram.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">Facebook URL</label>
              <input type="url" name="facebook_url" class="form-control" placeholder="https://facebook.com/yourpage">
            </div>
            <div class="col-md-6">
              <label class="form-label">Twitter URL</label>
              <input type="url" name="twitter_url" class="form-control" placeholder="https://twitter.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">TikTok URL</label>
              <input type="url" name="tiktok_url" class="form-control" placeholder="https://www.tiktok.com/@yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label">Ownership type</label>
              <select name="ownership_type_id" class="form-select">
                <option value="">Select...</option>
                @foreach($ownershipTypes as $type)
                  <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Business type</label>
              <select name="business_type_id" class="form-select">
                <option value="">Select...</option>
                @foreach($businessTypes as $type)
                  <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="createStoreSubmitBtn" class="btn btn-primary" disabled>Create Store</button>
        </div>
      </form>
    </div>
  </div>
</div>

@push('scripts')
<script>
  window.handleLogoPreview = function(event, previewId) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      var img = document.getElementById(previewId);
      if (img) img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  };

  document.addEventListener('DOMContentLoaded', function () {
    // Copy store URL
    document.querySelectorAll('.copy-store-url').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var url = this.getAttribute('data-url');
        if (!url) return;
        navigator.clipboard.writeText(url).then(() => {
          this.innerHTML = '<i class="fi fi-rr-check"></i>';
          setTimeout(() => { this.innerHTML = '<i class="fi fi-rr-copy"></i>'; }, 1500);
        }).catch(() => alert('Unable to copy link right now.'));
      });
    });

    // Populate edit modal
    var editModal = document.getElementById('editStoreModal');
    if (!editModal) return;
    editModal.addEventListener('show.bs.modal', function (event) {
      var button = event.relatedTarget;
      if (!button) return;
      var form = document.getElementById('editStoreForm');
      var action = button.getAttribute('data-action');
      if (form && action) {
        form.action = action;
      }

      var setValue = function(id, value) {
        var el = document.getElementById(id);
        if (el) el.value = value || '';
      };

      setValue('editStoreName', button.getAttribute('data-name'));
      setValue('editStoreSlug', button.getAttribute('data-slug'));
      setValue('editStoreDescription', button.getAttribute('data-description'));
      setValue('editStoreSupportEmail', button.getAttribute('data-support-email'));
      setValue('editStoreSupportPhone', button.getAttribute('data-support-phone'));
      setValue('editStoreAddress', button.getAttribute('data-address'));
      setValue('editStoreInstagramUrl', button.getAttribute('data-instagram-url'));
      setValue('editStoreFacebookUrl', button.getAttribute('data-facebook-url'));
      setValue('editStoreTwitterUrl', button.getAttribute('data-twitter-url'));
      setValue('editStoreTiktokUrl', button.getAttribute('data-tiktok-url'));
      setValue('editStoreOwnershipType', button.getAttribute('data-ownership-type-id'));
      setValue('editStoreBusinessType', button.getAttribute('data-business-type-id'));

      var logoPreview = document.getElementById('editStoreLogoPreview');
      if (logoPreview) {
        logoPreview.src = button.getAttribute('data-logo-url') || '';
      }
    });

    // Slug availability checker for creation
    const createNameInput = document.getElementById('createStoreName');
    const createSlugInput = document.getElementById('createStoreSlug');
    const createSlugFeedback = document.getElementById('createStoreSlugFeedback');
    const createSlugStatus = document.getElementById('createStoreSlugStatus');
    const createSubmitBtn = document.getElementById('createStoreSubmitBtn');
    const checkSlugUrl = '{{ route("vendor.kyc.store.check-slug", ["vendor" => $vendor]) }}';
    const csrfToken = '{{ csrf_token() }}';
    
    let debounceTimer = null;
    let currentAbortController = null;

    function setCreateSubmitState(enabled) {
        if (!createSubmitBtn) return;
        createSubmitBtn.disabled = !enabled;
    }

    function showCreateFeedback(type, message, suggestedSlug = null) {
        if (!createSlugFeedback || !createSlugStatus) return;
        createSlugFeedback.style.display = 'block';
        
        let icon = '';
        let colorClass = '';
        let extraHtml = '';
        
        switch (type) {
            case 'checking':
                icon = '<span class="spinner-border spinner-border-sm" role="status"></span>';
                colorClass = 'text-muted';
                break;
            case 'available':
                icon = '<i class="fi fi-rr-check-circle text-success"></i>';
                colorClass = 'text-success';
                break;
            case 'taken':
                icon = '<i class="fi fi-rr-cross-circle text-danger"></i>';
                colorClass = 'text-danger';
                if (suggestedSlug) {
                    extraHtml = ` <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="useSuggestedCreate" data-slug="${suggestedSlug}">Use "${suggestedSlug}" instead</button>`;
                }
                break;
            case 'error':
                icon = '<i class="fi fi-rr-warning text-warning"></i>';
                colorClass = 'text-warning';
                break;
        }
        
        createSlugStatus.className = 'd-flex align-items-center gap-2 ' + colorClass;
        createSlugStatus.innerHTML = icon + ' <span>' + message + '</span>' + extraHtml;
        
        const useSuggestedBtn = document.getElementById('useSuggestedCreate');
        if (useSuggestedBtn) {
            useSuggestedBtn.addEventListener('click', function() {
                const suggested = this.getAttribute('data-slug');
                createSlugInput.value = suggested;
                setCreateSubmitState(true);
                showCreateFeedback('available', `Your store link: <strong>${suggested}.{{ config('app.main_domain') }}</strong>`);
            });
        }
    }

    function checkCreateSlug(storeName) {
        if (currentAbortController) {
            currentAbortController.abort();
        }
        
        const previewSlug = storeName.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
        
        if (previewSlug.length < 2) {
            createSlugFeedback.style.display = 'none';
            createSlugInput.value = '';
            setCreateSubmitState(false);
            return;
        }
        
        showCreateFeedback('checking', 'Checking store link availability...');
        
        currentAbortController = new AbortController();
        
        fetch(checkSlugUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ slug: storeName }),
            signal: currentAbortController.signal
        })
        .then(response => response.json())
        .then(data => {
            if (data.available) {
                createSlugInput.value = data.slug;
                setCreateSubmitState(true);
                showCreateFeedback('available', `Your store link: <strong>${data.slug}.{{ config('app.main_domain') }}</strong>`);
            } else {
                createSlugInput.value = '';
                setCreateSubmitState(false);
                showCreateFeedback('taken', data.message, data.suggested);
            }
        })
        .catch(error => {
            if (error.name === 'AbortError') return;
            console.error('Slug check error:', error);
            createSlugInput.value = '';
            setCreateSubmitState(false);
            showCreateFeedback('error', 'Unable to check availability. Please try again.');
        });
    }

    if (createNameInput) {
        createNameInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const value = this.value.trim();
            
            if (value.length < 2) {
                createSlugFeedback.style.display = 'none';
                createSlugInput.value = '';
                setCreateSubmitState(false);
                return;
            }
            
            debounceTimer = setTimeout(() => {
                checkCreateSlug(value);
            }, 500);
        });
    }
  });
</script>
@endpush
@endsection
