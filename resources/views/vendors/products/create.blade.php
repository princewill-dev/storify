@extends('vendors.layout')
@section('subtitle', 'Create product')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Create product</h4>
    <a href="{{ route('vendor.products.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('vendor.products.store', ['vendor' => $vendor]) }}" enctype="multipart/form-data">
        @csrf
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Store</label>
            @if(isset($stores) && $stores->count() === 1)
              @php($only = $stores->first())
              <input type="hidden" name="store_id" value="{{ $only->id }}">
              <div class="form-control bg-light">{{ $only->name }}</div>
            @else
              <select name="store_id" class="form-select" required>
                <option value="">Select store</option>
                @foreach($stores as $s)
                  <option value="{{ $s->id }}" @selected(old('store_id', $selectedStoreId ?? null)==$s->id)>{{ $s->name }}</option>
                @endforeach
              </select>
            @endif
          </div>
          
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Brand</label>
            <input type="text" name="brand" class="form-control" value="{{ old('brand') }}" placeholder="Apple, Samsung, ...">
          </div>
          <div class="col-md-3">
            <label class="form-label">Quantity <span class="text-muted small">(remaining)</span></label>
            <input type="number" name="quantity" min="1" class="form-control" value="{{ old('quantity', 1) }}" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Initial Stock <span class="text-muted small">(optional)</span></label>
            <input type="number" name="stock_quantity" min="1" class="form-control" value="{{ old('stock_quantity') }}" placeholder="e.g. 100">
            <small class="text-muted">Total units you started with. Sold = Initial − Remaining.</small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Amount</label>
            <div class="input-group">
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="{{ old('amount') }}" required>
              <select name="currency_id" class="form-select" style="max-width: 140px;">
                @foreach(($currencies ?? []) as $cur)
                  <option value="{{ $cur->id }}" @selected(old('currency_id', $defaultCurrencyId ?? null) == $cur->id)>{{ $cur->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Discount (%)</label>
            <input type="number" name="discount_percentage" step="0.01" min="0" max="100" class="form-control" value="{{ old('discount_percentage') }}" placeholder="e.g. 10">
            <small class="text-muted">Optional. Final price = Amount × (1 − discount/100).</small>
          </div>

          <div class="col-md-4">
            <label class="form-label">Category</label>
            <div class="input-group">
              <select name="category_id" id="category-select" class="form-select">
                <option value="">select category</option>
                @foreach($categories as $c)
                  <option value="{{ $c->id }}" @selected(old('category_id')==$c->id)>{{ $c->name }}</option>
                @endforeach
              </select>
              <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
                <i class="bi bi-plus-lg"></i> Add New
              </button>
            </div>
          </div>
          
          <div class="col-md-4">
            <label class="form-label">Size</label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" name="size" class="form-control" value="{{ old('size') }}" placeholder="e.g. 15">
              <select name="size_unit_id" class="form-select" style="max-width: 160px;">
                <option value="">—</option>
                @foreach($sizeUnits as $u)
                  <option value="{{ $u->id }}" @selected(old('size_unit_id')==$u->id)>{{ $u->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Weight</label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" name="weight" class="form-control" value="{{ old('weight') }}" placeholder="e.g. 1.2">
              <select name="weight_unit_id" class="form-select" style="max-width: 160px;">
                <option value="">—</option>
                @foreach($weightUnits as $u)
                  <option value="{{ $u->id }}" @selected(old('weight_unit_id')==$u->id)>{{ $u->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Color</label>
            <input type="text" name="color" class="form-control" value="{{ old('color') }}" placeholder="e.g. Space Gray">
          </div>
          <div class="col-md-6">
            <label class="form-label">Tags</label>
            <input type="text" name="tags" class="form-control" value="{{ old('tags') }}" placeholder="comma separated e.g. laptop, pro, 2024">
          </div>
          <div class="col-12 mt-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="hasVariantsToggle" name="has_variants" value="1" {{ old('has_variants') ? 'checked' : '' }}>
              <label class="form-check-label" for="hasVariantsToggle">Has variants?</label>
            </div>
            <small class="text-muted">Enable to define multiple sizes, weights, colors with their own price and stock.</small>
          </div>

          <div class="col-12" id="variantsSection" style="display: none;">
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0">Variants</h6>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="addVariantRow()">Add Variant</button>
            </div>
            <div id="variantsContainer" class="row g-2"></div>
            <template id="variantRowTemplate">
              <div class="col-12 border rounded p-2 mb-2 variant-row">
                <div class="row g-2">
                  <div class="col-md-2">
                    <label class="form-label mb-1">Size</label>
                    <input type="number" step="0.01" class="form-control" name="__NAME__[size]" placeholder="e.g. 15">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label mb-1">Size Unit</label>
                    <select class="form-select" name="__NAME__[size_unit_id]">
                      <option value="">—</option>
                      @foreach(($sizeUnits ?? []) as $u)
                        <option value="{{ $u->id }}">{{ $u->code }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label mb-1">Weight</label>
                    <input type="number" step="0.01" class="form-control" name="__NAME__[weight]" placeholder="e.g. 1.2">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label mb-1">Weight Unit</label>
                    <select class="form-select" name="__NAME__[weight_unit_id]">
                      <option value="">—</option>
                      @foreach(($weightUnits ?? []) as $u)
                        <option value="{{ $u->id }}">{{ $u->code }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label mb-1">Color</label>
                    <input type="text" class="form-control" name="__NAME__[color]" placeholder="e.g. Red">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label mb-1">SKU</label>
                    <input type="text" class="form-control" name="__NAME__[sku]" placeholder="optional">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label mb-1">Quantity</label>
                    <input type="number" min="1" class="form-control" name="__NAME__[quantity]" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label mb-1">Amount</label>
                    <div class="input-group">
                      <input type="number" step="0.01" min="0.01" class="form-control" name="__NAME__[amount]" required>
                      <select class="form-select" name="__NAME__[currency_id]" style="max-width: 140px;">
                        @foreach(($currencies ?? []) as $cur)
                          <option value="{{ $cur->id }}" @selected(($defaultCurrencyId ?? null) == $cur->id)>{{ $cur->code }}</option>
                        @endforeach
                      </select>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label mb-1">Status</label>
                    <select class="form-select" name="__NAME__[status]">
                      <option value="active">active</option>
                      <option value="inactive">inactive</option>
                    </select>
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="__NAME__[featured]" value="1" id="__ID__">
                      <label class="form-check-label" for="__ID__">Featured</label>
                    </div>
                  </div>
                  <div class="col-md-2 d-flex align-items-end justify-content-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeVariantRow(this)">Remove</button>
                  </div>
                </div>
              </div>
            </template>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cod_available" id="cod_available" value="1" {{ old('cod_available') ? 'checked' : '' }}>
              <label class="form-check-label" for="cod_available">Can be paid on delivery</label>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="featured" id="featured" value="1" {{ old('featured', false) ? 'checked' : '' }}>
              <label class="form-check-label" for="featured">Mark as featured</label>
            </div>
          </div>
          
          <div class="col-12">
            <label class="form-label">Description</label>
            <input id="product-description" type="hidden" name="description" value="{{ old('description') }}">
            <trix-editor input="product-description" class="form-control"></trix-editor>
          </div>

          <div class="col-12">
            <label class="form-label">Product Media (Images/Videos)</label>
            <input type="file" name="images[]" class="form-control" accept="image/*,video/*" multiple id="images-input">
            <small class="text-muted">Upload up to 5 media files (max 300MB each). First selected media will be primary by default. Click on any media to change primary.</small>
            <input type="hidden" name="primary_image" id="primary-image-index" value="0">
            
            <div class="mt-3 row g-3" id="image-previews"></div>
          </div>
          
          <script>
          (function(){
            const input = document.getElementById('images-input');
            const container = document.getElementById('image-previews');
            const hidden = document.getElementById('primary-image-index');
            
            if(!input || !container) return;
            
            let selectedFiles = [];
            const MAX_FILES = 5;
            const MAX_FILE_SIZE = 300 * 1024 * 1024; // 300MB in bytes
            
            input.addEventListener('change', function(e){
               container.innerHTML = '';
               selectedFiles = Array.from(input.files || []);
               
               // Validate max files
               if(selectedFiles.length > MAX_FILES) {
                   alert(`You can only upload up to ${MAX_FILES} media files.`);
                   input.value = '';
                   selectedFiles = [];
                   hidden.value = '0';
                   return;
               }
               
               // Validate file sizes
               for(let i = 0; i < selectedFiles.length; i++) {
                   if(selectedFiles[i].size > MAX_FILE_SIZE) {
                       alert(`File "${selectedFiles[i].name}" exceeds 300MB limit.`);
                       input.value = '';
                       selectedFiles = [];
                       hidden.value = '0';
                       return;
                   }
               }
               
               if(selectedFiles.length === 0) {
                   hidden.value = '0';
                   return;
               }
               
               // Reset primary to 0 on new selection
               hidden.value = '0';
               
               selectedFiles.forEach((file, index) => {
                   const col = document.createElement('div');
                   col.className = 'col-6 col-md-4 col-lg-3';
                   
                   const card = document.createElement('div');
                   card.className = 'card h-100 position-relative border cursor-pointer preview-card';
                   card.style.cursor = 'pointer';
                   // Add selected class to first one by default
                   if(index === 0) card.classList.add('border-primary', 'border-3');
                   
                   card.onclick = function() {
                       // Update hidden input
                       hidden.value = String(index);
                       
                       // Visual update
                       document.querySelectorAll('.preview-card').forEach(c => {
                           c.classList.remove('border-primary', 'border-3');
                           const badge = c.querySelector('.primary-badge');
                           if(badge) badge.remove();
                       });
                       
                       card.classList.add('border-primary', 'border-3');
                       addPrimaryBadge(card);
                   };
                   
                   const isVideo = file.type.startsWith('video/');
                   
                   if(isVideo) {
                       // Handle video preview
                       const video = document.createElement('video');
                       video.className = 'card-img-top';
                       video.style.height = '150px';
                       video.style.objectFit = 'cover';
                       video.style.backgroundColor = '#000';
                       video.muted = true;
                       
                       const url = URL.createObjectURL(file);
                       video.src = url;
                       
                       // Load video metadata to show first frame
                       video.addEventListener('loadeddata', function() {
                           video.currentTime = 0.1; // Show frame at 0.1s
                       });
                       
                       card.appendChild(video);
                       
                       // Add video icon overlay
                       const videoIcon = document.createElement('div');
                       videoIcon.className = 'position-absolute top-50 start-50 translate-middle';
                       videoIcon.innerHTML = '<i class="fas fa-play-circle text-white" style="font-size: 3rem; opacity: 0.8;"></i>';
                       card.appendChild(videoIcon);
                   } else {
                       // Handle image preview
                       const reader = new FileReader();
                       reader.onload = function(e) {
                           const img = document.createElement('img');
                           img.src = e.target.result;
                           img.className = 'card-img-top';
                           img.style.height = '150px';
                           img.style.objectFit = 'cover';
                           card.appendChild(img);
                       };
                       reader.readAsDataURL(file);
                   }
                   
                   // Add file info
                   const body = document.createElement('div');
                   body.className = 'card-body p-2 text-center';
                   const fileSize = (file.size / (1024 * 1024)).toFixed(2);
                   body.innerHTML = `
                       <small class="text-truncate d-block" title="${file.name}">${file.name}</small>
                       <small class="text-muted">${fileSize} MB</small>
                   `;
                   card.appendChild(body);
                   
                   if(index === 0) {
                       addPrimaryBadge(card);
                   }
                   
                   col.appendChild(card);
                   container.appendChild(col);
               });
            });
            
            function addPrimaryBadge(card) {
                const badge = document.createElement('span');
                badge.className = 'position-absolute top-0 start-0 badge bg-primary primary-badge m-2';
                badge.textContent = 'Primary';
                card.appendChild(badge);
            }
          })();
          </script>

          <div class="col-md-4">
            <label class="form-label">Visibility</label>
            <select name="status" class="form-select" required>
              <option value="active" @selected(old('status','active')=='active')>visible</option>
              <option value="inactive" @selected(old('status')=='inactive')>hidden</option>
            </select>
          </div>

        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit" id="submit-btn">
            <span id="submit-text">Create</span>
            <span id="submit-spinner" class="d-none">
              <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              Creating...
            </span>
          </button>
          <a class="btn btn-light" href="{{ route('admin.products.index') }}">Cancel</a>
        </div>
      </form>
      
      <script>
      (function(){
        const form = document.querySelector('form');
        const submitBtn = document.getElementById('submit-btn');
        const submitText = document.getElementById('submit-text');
        const submitSpinner = document.getElementById('submit-spinner');
        
        if(form && submitBtn) {
          form.addEventListener('submit', function(e) {
            // Show spinner and disable button
            submitText.classList.add('d-none');
            submitSpinner.classList.remove('d-none');
            submitBtn.disabled = true;
          });
        }
      })();
      </script>
    </div>
  </div>
</div>

<!-- New Category Modal -->
<div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="newCategoryModalLabel">Create New Category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="newCategoryForm">
          @csrf
          <div class="mb-3">
            <label for="newCategoryStore" class="form-label">Store</label>
            @if(isset($stores) && $stores->count() === 1)
              @php($only = $stores->first())
              <input type="hidden" id="newCategoryStore" value="{{ $only->id }}">
              <div class="form-control bg-light">{{ $only->name }}</div>
            @else
              <select id="newCategoryStore" class="form-select" required>
                <option value="">Select store</option>
                @foreach($stores as $s)
                  <option value="{{ $s->id }}" @selected(old('store_id', $selectedStoreId ?? null)==$s->id)>{{ $s->name }}</option>
                @endforeach
              </select>
            @endif
          </div>
          <div class="mb-3">
            <label for="newCategoryName" class="form-label">Category Name</label>
            <input type="text" class="form-control" id="newCategoryName" required>
            <div class="invalid-feedback" id="categoryNameError"></div>
          </div>
          <div class="mb-3">
            <label for="newCategoryStatus" class="form-label">Status</label>
            <select id="newCategoryStatus" class="form-select" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="saveCategoryBtn">Save Category</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.min.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.umd.min.js"></script>

<script>
  // Variants UI logic

  // Variants UI logic
  (function(){
    const toggle = document.getElementById('hasVariantsToggle');
    const section = document.getElementById('variantsSection');
    const container = document.getElementById('variantsContainer');
    const tmpl = document.getElementById('variantRowTemplate');

    function setBaseFieldsDisabled(disabled){
      const baseNames = ['quantity','stock_quantity','amount','size','size_unit_id','weight','weight_unit_id','color','currency_id'];
      baseNames.forEach(n => {
        const el = document.querySelector(`[name="${n}"]`);
        if (el) { el.disabled = disabled; }
      });
    }

    function showVariants(on){
      section.style.display = on ? 'block' : 'none';
      setBaseFieldsDisabled(on);
    }

    window.addVariantRow = function(){
      const idx = container.querySelectorAll('.variant-row').length;
      const html = tmpl.innerHTML
        .replaceAll('__NAME__', `variants[${idx}]`)
        .replaceAll('__ID__', `variant-featured-${idx}`);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html.trim();
      const node = wrapper.firstElementChild;
      container.appendChild(node);
    };

    window.removeVariantRow = function(btn){
      const row = btn.closest('.variant-row');
      if (row) row.remove();
      // Re-index names to keep them sequential
      Array.from(container.querySelectorAll('.variant-row')).forEach((rowEl, i) => {
        rowEl.querySelectorAll('input, select, textarea, label').forEach(el => {
          if (el.name && el.name.includes('variants[')) {
            el.name = el.name.replace(/variants\[[0-9]+\]/, `variants[${i}]`);
          }
          if (el.htmlFor && el.htmlFor.startsWith('variant-featured-')) {
            el.htmlFor = `variant-featured-${i}`;
          }
          if (el.id && el.id.startsWith('variant-featured-')) {
            el.id = `variant-featured-${i}`;
          }
        });
      });
    };

    if (toggle) {
      toggle.addEventListener('change', function(){
        const on = !!this.checked;
        showVariants(on);
        if (on && container.querySelectorAll('.variant-row').length === 0) {
          addVariantRow();
        }
      });
      // init
      const initOn = !!toggle.checked;
      showVariants(initOn);
      if (initOn && container.querySelectorAll('.variant-row').length === 0) {
        addVariantRow();
      }
    }
  })();

  // Category creation AJAX handler
  (function(){
    const saveBtn = document.getElementById('saveCategoryBtn');
    const modal = document.getElementById('newCategoryModal');
    const categorySelect = document.getElementById('category-select');
    const nameInput = document.getElementById('newCategoryName');
    const storeInput = document.getElementById('newCategoryStore');
    const statusSelect = document.getElementById('newCategoryStatus');
    const nameError = document.getElementById('categoryNameError');

    if (!saveBtn) return;

    saveBtn.addEventListener('click', function(){
      const name = nameInput.value.trim();
      const storeId = storeInput.value;
      const status = statusSelect.value;

      // Reset validation
      nameInput.classList.remove('is-invalid');
      nameError.textContent = '';

      if (!name) {
        nameInput.classList.add('is-invalid');
        nameError.textContent = 'Category name is required';
        return;
      }

      if (!storeId) {
        alert('Please select a store');
        return;
      }

      // Disable button and show loading
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

      // Make AJAX request
      fetch('{{ route("vendor.categories.store", ["vendor" => $vendor]) }}', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          store_id: storeId,
          name: name,
          status: status
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Add new option to select
          const option = new Option(data.category.name, data.category.id, true, true);
          categorySelect.add(option);
          
          // Close modal
          const bsModal = bootstrap.Modal.getInstance(modal);
          bsModal.hide();

          // Reset form
          nameInput.value = '';
          statusSelect.value = 'active';

          // Show success message
          const alert = document.createElement('div');
          alert.className = 'alert alert-success alert-dismissible fade show';
          alert.innerHTML = `
            <strong>Success!</strong> Category "${data.category.name}" created successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          `;
          document.querySelector('.container-fluid').insertBefore(alert, document.querySelector('.container-fluid').firstChild);
          setTimeout(() => alert.remove(), 5000);
        } else {
          throw new Error(data.message || 'Failed to create category');
        }
      })
      .catch(error => {
        nameInput.classList.add('is-invalid');
        nameError.textContent = error.message || 'An error occurred. Please try again.';
      })
      .finally(() => {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Category';
      });
    });

    // Reset form when modal is closed
    modal.addEventListener('hidden.bs.modal', function(){
      nameInput.value = '';
      statusSelect.value = 'active';
      nameInput.classList.remove('is-invalid');
      nameError.textContent = '';
    });
  })();
</script>
@endsection
