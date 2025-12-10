@extends('admin.layout')
@section('title', 'Superadmin')
@section('subtitle', $store->name . ' Slides')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Storefront Slides — {{ $store->name }}</h5>
  <a href="{{ route('admin.stores.show', $store) }}" class="btn btn-light btn-sm">Back to Store</a>
</div>
<div class="row">
  <div class="col-lg-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">Slides</h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSlidesModal">Add Slides</button>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="slidesTable">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Image</th>
                <th>Product</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              @forelse($slides as $slide)
                <tr draggable="true" data-slide-id="{{ $slide->id }}">
                  <td class="cursor-move text-muted">&#9776;</td>
                  <td>
                    @php($pimg = $slide->product?->primaryImage()?->path)
                    @if($pimg)
                      <img src="{{ asset('storage/' . $pimg) }}" alt="" style="width:72px; height:40px; object-fit:cover;"/>
                    @else
                      <div class="text-muted small">No image</div>
                    @endif
                  </td>
                  <td>
                    {{ $slide->product?->name ?? '—' }}
                    @if($slide->product)
                      <div class="small text-muted">Code: {{ $slide->product->product_code }} • ${{ number_format((float)($slide->product->amount ?? 0),2) }}</div>
                    @endif
                  </td>
                  <td><span class="badge bg-light text-dark">{{ $slide->status }}</span></td>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSlideModal{{ $slide->id }}">Edit</button>
                    <form action="{{ route('admin.storefront-slides.destroy', [$store, $slide]) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete slide?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                  </td>
                </tr>


                <div class="modal fade" id="editSlideModal{{ $slide->id }}" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">Edit Slide</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <form action="{{ route('admin.storefront-slides.update', [$store, $slide]) }}" method="POST" enctype="multipart/form-data" class="edit-slide-form" data-search-url="{{ route('api.admin.store-products.index', $store) }}">
                        @csrf @method('PUT')
                        <div class="modal-body">
                          <div class="row g-3">
                            <div class="col-md-8">
                              <label class="form-label">Product</label>
                              <input type="hidden" name="product_id" value="{{ $slide->product_id }}">
                              <input type="text" class="form-control product-search" placeholder="Search product by name, code, slug..." autocomplete="off">
                              <div class="list-group position-absolute w-100 shadow-sm d-none search-results" style="z-index: 1056; max-height: 260px; overflow:auto;"></div>
                              @if($slide->product)
                                <div class="small mt-2">Selected: <strong class="selected-product-name">{{ $slide->product->name }}</strong> • Code: <span class="selected-product-code">{{ $slide->product->product_code }}</span> • $<span class="selected-product-price">{{ number_format((float)($slide->product->amount ?? 0),2) }}</span> — <a class="selected-product-edit" target="_blank" href="{{ url('/superadmin/products/'.$slide->product_id.'/edit') }}">Edit product</a></div>
                              @else
                                <div class="small mt-2 text-muted">No product selected.</div>
                              @endif
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Status</label>
                              <select name="status" class="form-select">
                                <option value="active" @selected($slide->status==='active')>active</option>
                                <option value="inactive" @selected($slide->status==='inactive')>inactive</option>
                              </select>
                            </div>
                            
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                          <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>

                
              @empty
                <tr><td colspan="6" class="text-center text-muted">No slides yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>



<!-- Add Slides Modal -->
<div class="modal fade" id="addSlidesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Slides</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 d-flex gap-3 align-items-end">
          <div class="flex-grow-1">
            <label class="form-label">Search Products</label>
            <input type="text" class="form-control modal-product-search" placeholder="Type to search..." autocomplete="off">
          </div>
          <div style="width:220px">
            <label class="form-label">Status</label>
            <select class="form-select bulk-status">
              <option value="active" selected>active</option>
              <option value="inactive">inactive</option>
            </select>
          </div>
        </div>
        <div class="alert alert-danger d-none error-box"><ul class="mb-0 error-list"></ul></div>
        <div class="loading-spinner text-center my-3 d-none">
          <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
        </div>
        <div class="list-group products-list" style="max-height:420px; overflow:auto;"></div>
        <div class="text-center mt-3">
          <button type="button" class="btn btn-light btn-load-more d-none">Load more</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-add-selected" disabled>Add Selected</button>
      </div>
    </div>
  </div>
</div>




<script>
(function(){
  function getCsrf(){
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      || document.querySelector('input[name="_token"]')?.value
      || '';
  }
  const csrf = getCsrf();
  const existingIds = new Set(@json($slides->pluck('product_id')->filter()->values()));
  function debounce(fn, delay){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), delay); } }

  function setupSearch(container){
    const input = container.querySelector('.product-search');
    const results = container.querySelector('.search-results');
    const hiddenId = container.querySelector('input[name="product_id"]');
    const preview = container.querySelector('.selected-preview') || container.closest('form')?.querySelector('.selected-preview');
    const nameEl = container.querySelector('.selected-product-name') || container.closest('form')?.querySelector('.selected-product-name');
    const codeEl = container.querySelector('.selected-product-code') || container.closest('form')?.querySelector('.selected-product-code');
    const priceEl = container.querySelector('.selected-product-price') || container.closest('form')?.querySelector('.selected-product-price');
    const editEl = container.querySelector('.selected-product-edit') || container.closest('form')?.querySelector('.selected-product-edit');
    const searchUrl = container.closest('form').dataset.searchUrl;
    const buildEditUrl = (id)=> `${window.location.origin}/superadmin/products/${id}/edit`;
    const allowId = Number(hiddenId?.value || 0);

    function selectProduct(p){
      hiddenId.value = p.id;
      if (nameEl) nameEl.textContent = p.name;
      if (codeEl) codeEl.textContent = p.product_code;
      if (priceEl) priceEl.textContent = (Number(p.amount||0)).toFixed(2);
      if (editEl) editEl.href = buildEditUrl(p.id);
      if (preview) preview.classList.remove('d-none');
      results.classList.add('d-none');
      input.value = p.name;
    }

    const doSearch = debounce(async ()=>{
      const q = input.value.trim();
      if (!q) { results.classList.add('d-none'); results.innerHTML=''; return; }
      const url = new URL(searchUrl, window.location.origin);
      url.searchParams.set('q', q);
      try {
        const resp = await fetch(url, { headers: { 'Accept':'application/json' } });
        const items = await resp.json();
        results.innerHTML = '';
        items.forEach(p=>{
          const disabled = existingIds.has(p.id) && p.id !== allowId;
          const a = document.createElement('a');
          a.href = 'javascript:void(0)';
          a.className = 'list-group-item list-group-item-action';
          a.innerHTML = `${p.name} — ${p.product_code} — $${Number(p.amount||0).toFixed(2)} ${disabled ? '<span class="badge bg-secondary ms-2">Already in slides</span>' : ''}`;
          if (disabled){
            a.classList.add('disabled','text-muted');
            a.style.pointerEvents = 'none';
          } else {
            a.addEventListener('click', ()=> selectProduct(p));
          }
          results.appendChild(a);
        });
        results.classList.toggle('d-none', items.length===0);
      } catch(e) {
        console.error(e);
      }
    }, 300);

    input?.addEventListener('input', doSearch);
    input?.addEventListener('focus', ()=>{ if(results.children.length) results.classList.remove('d-none'); });
    document.addEventListener('click', (e)=>{ if (!results.contains(e.target) && e.target!==input) results.classList.add('d-none'); });
  }

  document.querySelectorAll('.edit-slide-form').forEach(form=>{
    form.dataset.searchUrl = "{{ route('api.admin.store-products.index', $store) }}";
    setupSearch(form);
  });

  // Drag-and-drop reorder
  const table = document.getElementById('slidesTable');
  if (table){
    let dragEl = null;
    table.querySelectorAll('tbody tr[draggable="true"]').forEach(row=>{
      row.addEventListener('dragstart', (e)=>{ dragEl = row; row.classList.add('opacity-50'); });
      row.addEventListener('dragend', ()=>{ dragEl?.classList.remove('opacity-50'); dragEl=null; sendOrder(); });
      row.addEventListener('dragover', (e)=>{ e.preventDefault(); const target = e.currentTarget; if (dragEl && target!==dragEl){ const tbody = target.parentNode; const rect = target.getBoundingClientRect(); const next = (e.clientY - rect.top) / rect.height > 0.5; tbody.insertBefore(dragEl, next ? target.nextSibling : target); }
      });
    });

    async function sendOrder(){
      const order = Array.from(table.querySelectorAll('tbody tr[draggable="true"]').values()).map(tr=> Number(tr.dataset.slideId));
      try {
        const resp = await fetch("{{ route('api.admin.storefront-slides.reorder', $store) }}", {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': getCsrf(), 'X-Requested-With':'XMLHttpRequest' },
          body: JSON.stringify({ order })
        });
        if (!resp.ok){
          const txt = await resp.text();
          console.error('Reorder failed', resp.status, txt);
        }
      } catch(e){ console.error(e); }
    }
  }

  // Add Slides Modal logic
  const modalEl = document.getElementById('addSlidesModal');
  if (modalEl){
    const modal = modalEl; // bootstrap reference not strictly needed
    const list = modal.querySelector('.products-list');
    const spinner = modal.querySelector('.loading-spinner');
    const searchInput = modal.querySelector('.modal-product-search');
    const statusSelect = modal.querySelector('.bulk-status');
    const addBtn = modal.querySelector('.btn-add-selected');
    const loadMoreBtn = modal.querySelector('.btn-load-more');
    const apiListUrl = "{{ route('api.admin.store-products.index', $store) }}";
    const apiBulkUrl = "{{ route('api.admin.storefront-slides.bulk', $store) }}";
    let page = 1, lastPage = 1, q = '';
    const errorBox = modal.querySelector('.error-box');
    const errorList = modal.querySelector('.error-list');
    const updateButtonState = ()=>{
      const anyChecked = !!list.querySelector('input[type="checkbox"]:checked:not(:disabled)');
      addBtn.disabled = !anyChecked;
    }

    async function fetchProducts(reset=false){
      if (reset){ page = 1; list.innerHTML=''; }
      spinner.classList.remove('d-none');
      try{
        const url = new URL(apiListUrl, window.location.origin);
        url.searchParams.set('page', String(page));
        url.searchParams.set('per_page', '20');
        if (q) url.searchParams.set('q', q);
        const resp = await fetch(url, { headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' } });
        if (!resp.ok){
          const txt = await resp.text();
          console.error('List products failed', resp.status, txt);
          return;
        }
        const json = await resp.json();
        const items = json.data || [];
        lastPage = json.last_page || 1;
        items.forEach(p=>{
          const row = document.createElement('label');
          row.className = 'list-group-item d-flex align-items-center gap-3';
          const disabled = existingIds.has(p.id);
          row.innerHTML = `
            <input type="checkbox" class="form-check-input" value="${p.id}" ${disabled ? 'disabled' : ''}>
            <img src="${p.primary_image_path ? `${window.location.origin}/storage/${p.primary_image_path}` : ''}" alt="" style="width:48px;height:32px;object-fit:cover;background:#f5f5f5">
            <div class="flex-grow-1">
              <div>${p.name} ${disabled ? '<span class=\"badge bg-secondary ms-2\">Already in slides</span>' : ''}</div>
              <div class="small text-muted">${p.product_code} • $${Number(p.amount||0).toFixed(2)}</div>
            </div>
          `;
          row.querySelector('input[type="checkbox"]').addEventListener('change', updateButtonState);
          if (disabled){ row.classList.add('opacity-50'); }
          list.appendChild(row);
        });
        loadMoreBtn.classList.toggle('d-none', page>=lastPage);
      } finally{
        spinner.classList.add('d-none');
      }
    }

    const doSearch = debounce(()=>{ q = searchInput.value.trim(); fetchProducts(true); }, 300);

    modal.addEventListener('shown.bs.modal', ()=>{ if (!list.children.length) fetchProducts(true); });
    searchInput.addEventListener('input', doSearch);
    loadMoreBtn.addEventListener('click', ()=>{ if (page<lastPage){ page++; fetchProducts(false); } });
    addBtn.addEventListener('click', async ()=>{
      const ids = Array.from(list.querySelectorAll('input[type="checkbox"]:checked')).map(i=> Number(i.value));
      if (!ids.length) return;
      addBtn.disabled = true;
      errorBox.classList.add('d-none');
      errorList.innerHTML = '';
      try{
        const resp = await fetch(apiBulkUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': getCsrf(), 'X-Requested-With':'XMLHttpRequest' },
          body: JSON.stringify({ product_ids: ids, status: statusSelect.value })
        });
        if (!resp.ok){
          let payload = null; let txt = '';
          try { payload = await resp.json(); } catch(e){ txt = await resp.text(); }
          const messages = [];
          if (payload && payload.errors){
            Object.values(payload.errors).forEach(arr=>{ (arr||[]).forEach(msg=> messages.push(String(msg))); });
          }
          if (!messages.length){ messages.push('Failed to add slides. Please try again.'); }
          errorList.innerHTML = messages.map(m=> `<li>${m}</li>`).join('');
          errorBox.classList.remove('d-none');
          console.error('Bulk add failed', resp.status, payload||txt);
          return;
        }
        const data = await resp.json();
        if (!data.ok){
          console.error('Bulk add error payload', data);
          errorList.innerHTML = '<li>Failed to add slides.</li>';
          errorBox.classList.remove('d-none');
          return;
        }
        showToast('Slides added successfully');
        window.location.reload();
      } finally { addBtn.disabled = false; }
    });

    // Toast helper
    function showToast(message){
      let holder = document.getElementById('toast-holder');
      if (!holder){
        holder = document.createElement('div');
        holder.id = 'toast-holder';
        holder.style.position = 'fixed';
        holder.style.top = '1rem';
        holder.style.right = '1rem';
        holder.style.zIndex = '1080';
        document.body.appendChild(holder);
      }
      const toast = document.createElement('div');
      toast.className = 'toast align-items-center text-white bg-success border-0 show';
      toast.setAttribute('role','alert');
      toast.style.minWidth = '220px';
      toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
      holder.appendChild(toast);
      setTimeout(()=>{ toast.remove(); }, 2500);
    }
  }
})();
</script>

@endsection


