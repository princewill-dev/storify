@extends('admin.layout')
@section('subtitle', 'Create product')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Create product</h2>
  @php($pre = $selectedStoreId ?? null)
  @if($pre && ($storeForBack = ($stores->firstWhere('id', $pre) ?? null)))
    <a href="{{ route('admin.stores.products.index', $storeForBack) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
  @else
    <a href="{{ route('admin.products.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
  @endif
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
  <div class="p-6">
    <form method="post" action="{{ route('admin.products.store') }}" enctype="multipart/form-data">
      @csrf
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
          @if(isset($stores) && $stores->count() === 1)
            @php($only = $stores->first())
            <input type="hidden" name="store_id" value="{{ $only->id }}">
            <div class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $only->name }}</div>
          @else
            <select name="store_id" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
              <option value="">Select store</option>
              @foreach($stores as $s)
                <option value="{{ $s->id }}" @selected(old('store_id', $selectedStoreId ?? null)==$s->id)>{{ $s->name }}</option>
              @endforeach
            </select>
          @endif
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
          <select name="category_id" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            <option value="">—</option>
            @foreach($categories as $c)
              <option value="{{ $c->id }}" @selected(old('category_id')==$c->id)>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select name="status" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
            <option value="active" @selected(old('status','active')=='active')>active</option>
            <option value="inactive" @selected(old('status')=='inactive')>inactive</option>
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('name') }}" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Brand</label>
          <input type="text" name="brand" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('brand') }}" placeholder="Apple, Samsung, ...">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Quantity</label>
          <input type="number" name="quantity" min="1" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('quantity', 1) }}" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount</label>
          <div class="flex rounded-lg border border-slate-300 focus-within:border-slate-500 focus-within:ring-1 focus-within:ring-slate-500">
            <input type="number" name="amount" step="0.01" min="0.01" class="flex-1 border-0 rounded-l-lg px-3 py-2 text-sm focus:outline-none" value="{{ old('amount') }}" required>
            <select name="currency_id" class="border-0 border-l border-slate-300 rounded-r-lg px-3 py-2 text-sm focus:outline-none bg-white max-w-[140px]">
              @foreach(($currencies ?? []) as $cur)
                <option value="{{ $cur->id }}" @selected(old('currency_id', $defaultCurrencyId ?? null) == $cur->id)>{{ $cur->code }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Discount (%)</label>
          <input type="number" name="discount_percentage" step="0.01" min="0" max="100" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('discount_percentage') }}" placeholder="e.g. 10">
          <p class="text-xs text-slate-400 mt-1">Optional. Final price = Amount × (1 − discount/100).</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Size</label>
          <div class="flex rounded-lg border border-slate-300 focus-within:border-slate-500 focus-within:ring-1 focus-within:ring-slate-500">
            <input type="number" step="0.01" min="0" name="size" class="flex-1 border-0 rounded-l-lg px-3 py-2 text-sm focus:outline-none" value="{{ old('size') }}" placeholder="e.g. 15">
            <select name="size_unit_id" class="border-0 border-l border-slate-300 rounded-r-lg px-3 py-2 text-sm focus:outline-none bg-white max-w-[160px]">
              <option value="">—</option>
              @foreach($sizeUnits as $u)
                <option value="{{ $u->id }}" @selected(old('size_unit_id')==$u->id)>{{ $u->code }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Weight</label>
          <div class="flex rounded-lg border border-slate-300 focus-within:border-slate-500 focus-within:ring-1 focus-within:ring-slate-500">
            <input type="number" step="0.01" min="0" name="weight" class="flex-1 border-0 rounded-l-lg px-3 py-2 text-sm focus:outline-none" value="{{ old('weight') }}" placeholder="e.g. 1.2">
            <select name="weight_unit_id" class="border-0 border-l border-slate-300 rounded-r-lg px-3 py-2 text-sm focus:outline-none bg-white max-w-[160px]">
              <option value="">—</option>
              @foreach($weightUnits as $u)
                <option value="{{ $u->id }}" @selected(old('weight_unit_id')==$u->id)>{{ $u->code }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="md:col-span-1">
          <label class="block text-sm font-medium text-slate-700 mb-1">Color</label>
          <input type="text" name="color" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('color') }}" placeholder="e.g. Space Gray">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Tags</label>
          <input type="text" name="tags" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('tags') }}" placeholder="comma separated e.g. laptop, pro, 2024">
        </div>

        <div class="md:col-span-3 mt-2">
          <div class="flex items-center gap-3">
            <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" id="hasBulkToggle">
            <label class="text-sm text-slate-700" for="hasBulkToggle">Has Bulk Pricing?</label>
          </div>
          <p class="text-xs text-slate-400 mt-1">Enable to set a bulk quantity threshold and a discounted price.</p>
        </div>

        <div class="md:col-span-3" id="bulkSection" style="display: none;">
          <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Bulk Quantity (Threshold)</label>
                <input type="number" name="bulk_quantity" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('bulk_quantity') }}" placeholder="e.g. 50">
                <p class="text-xs text-slate-400 mt-1">Minimum quantity to qualify for bulk price.</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Bulk Price (Total for Threshold)</label>
                <input type="number" step="0.01" name="bulk_price" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('bulk_price') }}" placeholder="e.g. 2000.00">
                <p class="text-xs text-slate-400 mt-1">Total price for the bulk quantity (e.g. Price for 50 units).</p>
              </div>
            </div>
          </div>
        </div>

        <div class="md:col-span-3 mt-2">
          <div class="flex items-center gap-3">
            <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" id="hasVariantsToggle" name="has_variants" value="1" {{ old('has_variants') ? 'checked' : '' }}>
            <label class="text-sm text-slate-700" for="hasVariantsToggle">Has variants?</label>
          </div>
          <p class="text-xs text-slate-400 mt-1">Enable to define multiple sizes, weights, colors with their own price and stock.</p>
        </div>

        <div class="md:col-span-3" id="variantsSection" style="display: none;">
          <hr class="border-slate-200 my-3">
          <div class="flex items-center justify-between mb-3">
            <h6 class="text-sm font-semibold text-slate-900">Variants</h6>
            <button type="button" onclick="addVariantRow()" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Add Variant</button>
          </div>
          <div id="variantsContainer" class="grid grid-cols-1 gap-2"></div>
          <template id="variantRowTemplate">
            <div class="border border-slate-200 rounded-lg p-3 variant-row">
              <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">Size</label>
                  <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[size]" placeholder="e.g. 15">
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">Size Unit</label>
                  <select class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[size_unit_id]">
                    <option value="">—</option>
                    @foreach(($sizeUnits ?? []) as $u)
                      <option value="{{ $u->id }}">{{ $u->code }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">Weight</label>
                  <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[weight]" placeholder="e.g. 1.2">
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">Weight Unit</label>
                  <select class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[weight_unit_id]">
                    <option value="">—</option>
                    @foreach(($weightUnits ?? []) as $u)
                      <option value="{{ $u->id }}">{{ $u->code }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">Color</label>
                  <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[color]" placeholder="e.g. Red">
                </div>
                <div class="md:col-span-2">
                  <label class="block text-xs text-slate-600 mb-1">SKU</label>
                  <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[sku]" placeholder="optional">
                </div>
                <div class="md:col-span-3">
                  <label class="block text-xs text-slate-600 mb-1">Quantity</label>
                  <input type="number" min="1" class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[quantity]" required>
                </div>
                <div class="md:col-span-4">
                  <label class="block text-xs text-slate-600 mb-1">Amount</label>
                  <div class="flex rounded-lg border border-slate-300 focus-within:border-slate-500 focus-within:ring-1 focus-within:ring-slate-500">
                    <input type="number" step="0.01" min="0.01" class="flex-1 border-0 rounded-l-lg px-3 py-1.5 text-sm focus:outline-none" name="__NAME__[amount]" required>
                    <select class="border-0 border-l border-slate-300 rounded-r-lg px-3 py-1.5 text-sm focus:outline-none bg-white max-w-[140px]" name="__NAME__[currency_id]">
                      @foreach(($currencies ?? []) as $cur)
                        <option value="{{ $cur->id }}" @selected(($defaultCurrencyId ?? null) == $cur->id)>{{ $cur->code }}</option>
                      @endforeach
                    </select>
                  </div>
                </div>
                <div class="md:col-span-3">
                  <label class="block text-xs text-slate-600 mb-1">Status</label>
                  <select class="w-full rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" name="__NAME__[status]">
                    <option value="active">active</option>
                    <option value="inactive">inactive</option>
                  </select>
                </div>
                <div class="md:col-span-2 flex items-end">
                  <div class="flex items-center gap-2">
                    <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" name="__NAME__[featured]" value="1" id="__ID__">
                    <label class="text-xs text-slate-600" for="__ID__">Featured</label>
                  </div>
                </div>
                <div class="md:col-span-2 flex items-end justify-end">
                  <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50" onclick="removeVariantRow(this)">Remove</button>
                </div>
              </div>
            </div>
          </template>
        </div>

        <div class="md:col-span-3">
          <div class="flex items-center gap-3">
            <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" name="cod_available" id="cod_available" value="1" {{ old('cod_available', true) ? 'checked' : '' }}>
            <label class="text-sm text-slate-700" for="cod_available">Can be paid on delivery</label>
          </div>
        </div>

        <div class="md:col-span-3">
          <div class="flex items-center gap-3">
            <input class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" name="featured" id="featured" value="1" {{ old('featured', false) ? 'checked' : '' }}>
            <label class="text-sm text-slate-700" for="featured">Mark as featured</label>
          </div>
        </div>

        <div class="md:col-span-3">
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <input id="product-description" type="hidden" name="description" value="{{ old('description') }}">
          <trix-editor input="product-description" class="trix-content rounded-lg border border-slate-300"></trix-editor>
        </div>

        <div class="md:col-span-3">
          <label class="block text-sm font-medium text-slate-700 mb-1">Images</label>
          <input type="file" name="images[]" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept="image/*" multiple id="images-input">
          <input type="hidden" name="primary_image" id="primary-image-index" value="0">
          <div class="mt-3" id="primary-picker" style="display:none;">
            <div class="text-sm font-medium text-slate-700 mb-2">Choose primary image</div>
            <div id="primary-options" class="flex flex-wrap gap-3"></div>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3 mt-6 pt-4 border-t border-slate-100">
        <button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800" type="submit">Create</button>
        <a class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50" href="{{ route('admin.products.index') }}">Cancel</a>
      </div>
    </form>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.min.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.umd.min.js"></script>

<script>
(function(){
  const input = document.getElementById('images-input');
  const picker = document.getElementById('primary-picker');
  const options = document.getElementById('primary-options');
  const hidden = document.getElementById('primary-image-index');
  if(!input) return;
  input.addEventListener('change', function(){
    options.innerHTML='';
    const files = Array.from(input.files || []);
    if(files.length===0){ picker.style.display='none'; return; }
    picker.style.display='block';
    files.forEach((f, idx)=>{
      const id = 'prim-'+idx;
      const wrapper = document.createElement('label');
      wrapper.className = 'inline-flex items-center gap-2 border border-slate-200 rounded-lg p-2 cursor-pointer';
      wrapper.innerHTML = `<input type="radio" name="_primary_pick" ${idx===0?'checked':''} value="${idx}" id="${id}" class="rounded-full border-slate-300 text-slate-900 focus:ring-slate-500"><span class="text-sm text-slate-700">${f.name}</span>`;
      wrapper.querySelector('input').addEventListener('change', ()=>{ hidden.value = String(idx); });
      options.appendChild(wrapper);
    });
    hidden.value = '0';
  });
})();

  (function(){
    const toggle = document.getElementById('hasBulkToggle');
    const section = document.getElementById('bulkSection');
    const qtyInput = document.querySelector('input[name="bulk_quantity"]');
    const priceInput = document.querySelector('input[name="bulk_price"]');

    function showBulk(on){
      section.style.display = on ? 'block' : 'none';
      if (!on) {
        qtyInput.value = '';
        priceInput.value = '';
      }
    }

    if (toggle) {
      toggle.addEventListener('change', function(){
        showBulk(this.checked);
      });
      if (qtyInput.value || priceInput.value) {
        toggle.checked = true;
        showBulk(true);
      }
    }
  })();

  (function(){
    const toggle = document.getElementById('hasVariantsToggle');
    const section = document.getElementById('variantsSection');
    const container = document.getElementById('variantsContainer');
    const tmpl = document.getElementById('variantRowTemplate');

    function setBaseFieldsDisabled(disabled){
      const baseNames = ['quantity','amount','size','size_unit_id','weight','weight_unit_id','color','currency_id'];
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
      const initOn = !!toggle.checked;
      showVariants(initOn);
      if (initOn && container.querySelectorAll('.variant-row').length === 0) {
        addVariantRow();
      }
    }
  })();
</script>
@endsection
