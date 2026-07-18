@extends('admin.layout')
@section('subtitle', $store->name . ' Slides')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Storefront Slides — {{ $store->name }}</h2>
  <a href="{{ route('admin.stores.show', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Back to Store</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
    <h3 class="text-sm font-semibold text-slate-800">Slides</h3>
    <button type="button" onclick="openModal('addSlidesModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Add Slides</button>
  </div>

  <table class="w-full text-sm" id="slidesTable">
    <thead class="border-b border-slate-100">
      <tr>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-10">#</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Image</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Product</th>
        <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
        <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-50">
      @forelse($slides as $slide)
        <tr draggable="true" data-slide-id="{{ $slide->id }}" class="hover:bg-slate-50/50">
          <td class="py-3 px-4 cursor-move text-slate-300">&#9776;</td>
          <td class="py-3 px-4">
            @php($pimg = $slide->product?->primaryImage()?->path)
            @if($pimg)
              <img src="{{ asset('storage/' . $pimg) }}" alt="" class="w-[72px] h-10 object-cover rounded border border-slate-200"/>
            @else
              <div class="text-xs text-slate-400">No image</div>
            @endif
          </td>
          <td class="py-3 px-4">
            <div class="text-slate-700">{{ $slide->product?->name ?? '—' }}</div>
            @if($slide->product)
              <div class="text-xs text-slate-400">Code: {{ $slide->product->product_code }} &bull; ${{ number_format((float)($slide->product->amount ?? 0),2) }}</div>
            @endif
          </td>
          <td class="py-3 px-4"><span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ $slide->status }}</span></td>
          <td class="py-3 px-4 text-right">
            <div class="flex items-center justify-end gap-1">
              <button type="button" onclick="openEditSlideModal('{{ $slide->id }}', '{{ addslashes($slide->product?->name ?? '') }}', '{{ $slide->product?->product_code ?? '' }}', '{{ number_format((float)($slide->product?->amount ?? 0),2) }}', '{{ $slide->product_id }}', '{{ $slide->status }}')" class="inline-flex items-center justify-center p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:text-slate-800 hover:bg-slate-50 text-xs">Edit</button>
              <button type="button" onclick="openModal('deleteSlide{{ $slide->id }}')" class="inline-flex items-center justify-center p-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 text-xs">Delete</button>
              <x-admin.confirm-modal id="deleteSlide{{ $slide->id }}" title="Delete Slide" message="Delete slide?" action="{{ route('admin.storefront-slides.destroy', [$store, $slide]) }}" method="DELETE" />
            </div>
          </td>
        </tr>

        {{-- Edit Slide Modal --}}
        <div id="editSlideModal{{ $slide->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
          <div class="flex items-center justify-center min-h-screen p-4">
            <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editSlideModal{{ $slide->id }}')"></div>
            <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6">
              <div class="flex items-center justify-between mb-4">
                <h5 class="text-base font-semibold text-slate-900">Edit Slide</h5>
                <button onclick="closeModal('editSlideModal{{ $slide->id }}')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
              </div>
              <form action="{{ route('admin.storefront-slides.update', [$store, $slide]) }}" method="POST" enctype="multipart/form-data" class="edit-slide-form space-y-4" data-search-url="{{ route('api.admin.store-products.index', $store) }}">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Product</label>
                    <input type="hidden" name="product_id" value="{{ $slide->product_id }}">
                    <input type="text" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 product-search" placeholder="Search product by name, code, slug..." autocomplete="off">
                    <div class="relative w-full"><div class="list-group absolute w-full bg-white rounded-lg shadow-lg border border-slate-200 d-none search-results z-10 max-h-64 overflow-auto"></div></div>
                    @if($slide->product)
                      <div class="mt-2 text-xs text-slate-500">Selected: <strong class="text-slate-700 selected-product-name">{{ $slide->product->name }}</strong> &bull; Code: <span class="text-slate-700 selected-product-code">{{ $slide->product->product_code }}</span> &bull; $<span class="text-slate-700 selected-product-price">{{ number_format((float)($slide->product->amount ?? 0),2) }}</span> — <a class="text-indigo-600 hover:underline selected-product-edit" target="_blank" href="{{ url('/superadmin/products/'.$slide->product_id.'/edit') }}">Edit product</a></div>
                    @else
                      <div class="mt-2 text-xs text-slate-400">No product selected.</div>
                    @endif
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                      <option value="active" @selected($slide->status==='active')>active</option>
                      <option value="inactive" @selected($slide->status==='inactive')>inactive</option>
                    </select>
                  </div>
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                  <button type="button" onclick="closeModal('editSlideModal{{ $slide->id }}')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Close</button>
                  <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      @empty
        <tr><td colspan="5" class="py-12 text-center text-slate-400">No slides yet.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<!-- Add Slides Modal -->
<div id="addSlidesModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('addSlidesModal')"></div>
    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h5 class="text-base font-semibold text-slate-900">Add Slides</h5>
        <button onclick="closeModal('addSlidesModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
      </div>
      <div class="flex flex-wrap items-end gap-3 mb-4">
        <div class="flex-1 min-w-[200px]">
          <label class="block text-sm font-medium text-slate-700 mb-1">Search Products</label>
          <input type="text" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 modal-product-search" placeholder="Type to search..." autocomplete="off">
        </div>
        <div class="w-[160px]">
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 bulk-status">
            <option value="active" selected>active</option>
            <option value="inactive">inactive</option>
          </select>
        </div>
      </div>
      <div class="hidden mb-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 error-box"><ul class="mb-0 error-list list-disc pl-4"></ul></div>
      <div class="flex justify-center py-6 hidden loading-spinner">
        <svg class="animate-spin h-6 w-6 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
      </div>
      <div class="products-list space-y-0.5 max-h-[420px] overflow-auto"></div>
      <div class="text-center mt-3">
        <button type="button" class="hidden btn-load-more inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Load more</button>
      </div>
      <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 mt-4">
        <button type="button" onclick="closeModal('addSlidesModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Close</button>
        <button type="button" class="btn-add-selected inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Add Selected</button>
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
  const esc = (s) => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  const existingIds = new Set(@json($slides->pluck('product_id')->filter()->values()));
  function debounce(fn, delay){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), delay); } }

  function openEditSlideModal(id, productName, productCode, productPrice, productId, status) {
    openModal('editSlideModal' + id);
  }

  function setupSearch(container){
    const input = container.querySelector('.product-search');
    const results = container.querySelector('.search-results');
    const hiddenId = container.querySelector('input[name="product_id"]');
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
      results.classList.add('hidden');
      input.value = p.name;
    }

    const doSearch = debounce(async ()=>{
      const q = input.value.trim();
      if (!q) { results.classList.add('hidden'); results.innerHTML=''; return; }
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
          a.className = 'block px-3 py-2 text-sm hover:bg-slate-50 border-b border-slate-50';
          a.innerHTML = `${esc(p.name)} — ${esc(p.product_code)} — $${Number(p.amount||0).toFixed(2)} ${disabled ? '<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ml-2">Already in slides</span>' : ''}`;
          if (disabled){
            a.classList.add('text-slate-400','cursor-not-allowed');
            a.style.pointerEvents = 'none';
          } else {
            a.addEventListener('click', ()=> selectProduct(p));
          }
          results.appendChild(a);
        });
        results.classList.toggle('hidden', items.length===0);
      } catch(e) {
        console.error(e);
      }
    }, 300);

    input?.addEventListener('input', doSearch);
    input?.addEventListener('focus', ()=>{ if(results.children.length) results.classList.remove('hidden'); });
    document.addEventListener('click', (e)=>{ if (!results.contains(e.target) && e.target!==input) results.classList.add('hidden'); });
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
    const modal = modalEl;
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
      spinner.classList.remove('hidden');
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
          row.className = 'flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-50 border-b border-slate-50';
          const disabled = existingIds.has(p.id);
          row.innerHTML = `
            <input type="checkbox" class="form-check-input rounded border-slate-300 text-slate-900 focus:ring-slate-500" value="${p.id}" ${disabled ? 'disabled' : ''}>
            <img src="${p.primary_image_path ? `${window.location.origin}/storage/${p.primary_image_path}` : ''}" alt="" class="w-12 h-8 object-cover rounded border border-slate-200 bg-slate-50">
            <div class="flex-1 min-w-0">
              <div class="text-sm text-slate-700 truncate">${esc(p.name)} ${disabled ? '<span class="inline-flex items-center rounded-full bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-500 ml-1">Already in slides</span>' : ''}</div>
              <div class="text-xs text-slate-400">${esc(p.product_code)} &bull; $${Number(p.amount||0).toFixed(2)}</div>
            </div>
          `;
          row.querySelector('input[type="checkbox"]').addEventListener('change', updateButtonState);
          if (disabled){ row.classList.add('opacity-50'); }
          list.appendChild(row);
        });
        loadMoreBtn.classList.toggle('hidden', page>=lastPage);
      } finally{
        spinner.classList.add('hidden');
      }
    }

    const doSearch = debounce(()=>{ q = searchInput.value.trim(); fetchProducts(true); }, 300);

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.target.classList.contains('hidden') === false) {
          if (!list.children.length) fetchProducts(true);
        }
      });
    });
    observer.observe(modal, { attributes: true, attributeFilter: ['class'] });

    searchInput.addEventListener('input', doSearch);
    loadMoreBtn.addEventListener('click', ()=>{ if (page<lastPage){ page++; fetchProducts(false); } });
    addBtn.addEventListener('click', async ()=>{
      const ids = Array.from(list.querySelectorAll('input[type="checkbox"]:checked')).map(i=> Number(i.value));
      if (!ids.length) return;
      addBtn.disabled = true;
      errorBox.classList.add('hidden');
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
          errorList.innerHTML = messages.map(m=> `<li>${esc(m)}</li>`).join('');
          errorBox.classList.remove('hidden');
          console.error('Bulk add failed', resp.status, payload||txt);
          return;
        }
        const data = await resp.json();
        if (!data.ok){
          console.error('Bulk add error payload', data);
          errorList.innerHTML = '<li>Failed to add slides.</li>';
          errorBox.classList.remove('hidden');
          return;
        }
        showToast('Slides added successfully');
        window.location.reload();
      } finally { addBtn.disabled = false; }
    });

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
      toast.className = 'flex items-center gap-3 bg-emerald-600 text-white rounded-lg shadow-lg px-4 py-3 text-sm';
      toast.setAttribute('role','alert');
      toast.style.minWidth = '220px';
      toast.innerHTML = `<span>${esc(message)}</span><button type="button" class="text-white/70 hover:text-white ml-auto text-lg leading-none" onclick="this.parentElement.remove()">&times;</button>`;
      holder.appendChild(toast);
      setTimeout(()=>{ toast.remove(); }, 2500);
    }
  }
})();
</script>
@endsection
