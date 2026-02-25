@extends('vendors.layout')
@section('subtitle', 'Edit product')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Edit product</h4>
    <a href="{{ $backUrl ?? route('vendor.products.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('vendor.products.update', ['vendor' => $vendor, 'product' => $product]) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Store</label>
            <select name="store_id" class="form-select" required>
              @foreach($stores as $s)
                <option value="{{ $s->id }}" @selected(old('store_id', $product->store_id)==$s->id)>{{ $s->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">—</option>
              @foreach($categories as $c)
                <option value="{{ $c->id }}" @selected(old('category_id', $product->category_id)==$c->id)>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="active" @selected(old('status', $product->status)=='active')>active</option>
              <option value="inactive" @selected(old('status', $product->status)=='inactive')>inactive</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $product->name) }}" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Brand</label>
            <input type="text" name="brand" class="form-control" value="{{ old('brand', $product->brand) }}" placeholder="Apple, Samsung, ...">
          </div>
          <div class="col-md-3">
            <label class="form-label">Quantity <span class="text-muted small">(remaining)</span></label>
            <input type="number" name="quantity" min="1" class="form-control" value="{{ old('quantity', $product->quantity) }}" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Initial Stock</label>
            <input type="number" name="stock_quantity" min="1" class="form-control" value="{{ old('stock_quantity', $product->stock_quantity) }}" placeholder="e.g. 100">
            @if(!is_null($product->stock_quantity))
              <small class="text-muted">Sold: {{ number_format($product->soldQuantity()) }} unit(s)</small>
            @else
              <small class="text-muted">Total units you started with. Sold = Initial − Remaining.</small>
            @endif
          </div>
          <div class="col-md-4">
            <label class="form-label">Amount</label>
            <div class="input-group">
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="{{ old('amount', number_format($product->amount,2,'.','')) }}" required>
              <select name="currency_id" class="form-select" style="max-width: 140px;">
                @foreach(($currencies ?? []) as $cur)
                  <option value="{{ $cur->id }}" @selected(old('currency_id', $product->currency_id ?? ($defaultCurrencyId ?? null)) == $cur->id)>{{ $cur->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Discount (%)</label>
            <input type="number" name="discount_percentage" step="0.01" min="0" max="100" class="form-control" value="{{ old('discount_percentage', $product->discount_percentage) }}" placeholder="e.g. 10">
            <small class="text-muted">Optional. Final price = Amount × (1 − discount/100).</small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Size</label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" name="size" class="form-control" value="{{ old('size', $product->size) }}" placeholder="e.g. 15">
              <select name="size_unit_id" class="form-select" style="max-width: 160px;">
                <option value="">—</option>
                @foreach($sizeUnits as $u)
                  <option value="{{ $u->id }}" @selected(old('size_unit_id', $product->size_unit_id)==$u->id)>{{ $u->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Weight</label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" name="weight" class="form-control" value="{{ old('weight', $product->weight) }}" placeholder="e.g. 1.2">
              <select name="weight_unit_id" class="form-select" style="max-width: 160px;">
                <option value="">—</option>
                @foreach($weightUnits as $u)
                  <option value="{{ $u->id }}" @selected(old('weight_unit_id', $product->weight_unit_id)==$u->id)>{{ $u->code }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Color</label>
            <input type="text" name="color" class="form-control" value="{{ old('color', $product->color) }}" placeholder="e.g. Space Gray">
          </div>
          <div class="col-md-6">
            <label class="form-label">Tags</label>
            <input type="text" name="tags" class="form-control" value="{{ old('tags', $product->tags) }}" placeholder="comma separated e.g. laptop, pro, 2024">
          </div>
          <div class="col-12 mt-2">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="hasVariantsToggle" name="has_variants" value="1" {{ old('has_variants', $product->has_variants) ? 'checked' : '' }}>
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
            <div id="variantsContainer" class="row g-2">
              @php($oldVariants = collect(old('variants', $product->variants?->map(function($v){return [
                'id'=>$v->id,
                'size'=>$v->size,
                'size_unit_id'=>$v->size_unit_id,
                'weight'=>$v->weight,
                'weight_unit_id'=>$v->weight_unit_id,
                'color'=>$v->color,
                'sku'=>$v->sku,
                'quantity'=>$v->quantity,
                'amount'=>$v->amount,
                'currency_id'=>$v->currency_id,
                'status'=>$v->status,
                'featured'=>$v->featured,
              ];})) ))
              @foreach(($oldVariants ?? []) as $i => $v)
                <div class="col-12 border rounded p-2 mb-2 variant-row">
                  <div class="row g-2">
                    <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}">
                    <div class="col-md-2">
                      <label class="form-label mb-1">Size</label>
                      <input type="number" step="0.01" class="form-control" name="variants[{{ $i }}][size]" value="{{ $v['size'] ?? '' }}" placeholder="e.g. 15">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label mb-1">Size Unit</label>
                      <select class="form-select" name="variants[{{ $i }}][size_unit_id]">
                        <option value="">—</option>
                        @foreach(($sizeUnits ?? []) as $u)
                          <option value="{{ $u->id }}" @selected(($v['size_unit_id'] ?? '')==$u->id)>{{ $u->code }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label mb-1">Weight</label>
                      <input type="number" step="0.01" class="form-control" name="variants[{{ $i }}][weight]" value="{{ $v['weight'] ?? '' }}" placeholder="e.g. 1.2">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label mb-1">Weight Unit</label>
                      <select class="form-select" name="variants[{{ $i }}][weight_unit_id]">
                        <option value="">—</option>
                        @foreach(($weightUnits ?? []) as $u)
                          <option value="{{ $u->id }}" @selected(($v['weight_unit_id'] ?? '')==$u->id)>{{ $u->code }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label mb-1">Color</label>
                      <input type="text" class="form-control" name="variants[{{ $i }}][color]" value="{{ $v['color'] ?? '' }}" placeholder="e.g. Red">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label mb-1">SKU</label>
                      <input type="text" class="form-control" name="variants[{{ $i }}][sku]" value="{{ $v['sku'] ?? '' }}" placeholder="optional">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label mb-1">Quantity</label>
                      <input type="number" min="1" class="form-control" name="variants[{{ $i }}][quantity]" value="{{ $v['quantity'] ?? '' }}" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label mb-1">Amount</label>
                      <div class="input-group">
                        <input type="number" step="0.01" min="0.01" class="form-control" name="variants[{{ $i }}][amount]" value="{{ isset($v['amount']) ? number_format($v['amount'],2,'.','') : '' }}" required>
                        <select class="form-select" name="variants[{{ $i }}][currency_id]" style="max-width: 140px;">
                          @foreach(($currencies ?? []) as $cur)
                            <option value="{{ $cur->id }}" @selected(($v['currency_id'] ?? ($defaultCurrencyId ?? null)) == $cur->id)>{{ $cur->code }}</option>
                          @endforeach
                        </select>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label mb-1">Status</label>
                      <select class="form-select" name="variants[{{ $i }}][status]">
                        <option value="active" @selected(($v['status'] ?? 'active')==='active')>active</option>
                        <option value="inactive" @selected(($v['status'] ?? '')==='inactive')>inactive</option>
                      </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                      @php($fid = 'variant-featured-'.$i)
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="variants[{{ $i }}][featured]" value="1" id="{{ $fid }}" @checked(!empty($v['featured']))>
                        <label class="form-check-label" for="{{ $fid }}">Featured</label>
                      </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end justify-content-end">
                      <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeVariantRow(this)">Remove</button>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>
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
              <input class="form-check-input" type="checkbox" name="cod_available" id="cod_available" value="1" {{ old('cod_available', $product->cod_available) ? 'checked' : '' }}>
              <label class="form-check-label" for="cod_available">Can be paid on delivery</label>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="featured" id="featured" value="1" {{ old('featured', $product->featured) ? 'checked' : '' }}>
              <label class="form-check-label" for="featured">Mark as featured</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <input id="product-description" type="hidden" name="description" value="{{ old('description', $product->description) }}">
            <trix-editor input="product-description" class="form-control"></trix-editor>
          </div>

          <div class="col-12">
            <label class="form-label">Existing images</label>
            <div class="d-flex flex-wrap gap-3">
              @forelse($product->images as $img)
                <div class="border rounded p-2" style="width:160px;">
                  <img src="{{ asset('storage/'.$img->path) }}" alt="" class="img-fluid mb-2" style="max-height:120px;object-fit:contain;">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="primary_image_id" value="{{ $img->id }}" id="prim{{ $img->id }}" @checked(old('primary_image_id', $product->primaryImage()?->id) == $img->id)>
                    <label class="form-check-label" for="prim{{ $img->id }}">Primary</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="delete_image_ids[]" value="{{ $img->id }}" id="del{{ $img->id }}">
                    <label class="form-check-label" for="del{{ $img->id }}">Delete</label>
                  </div>
                </div>
              @empty
                <div class="text-muted">No images uploaded yet.</div>
              @endforelse
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Add images</label>
            <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Save changes</button>
          <a href="{{ $backUrl ?? route('vendor.products.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light">Back</a>
        </div>
      </form>
    </div>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.min.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.umd.min.js"></script>

<script>
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
  }
})();
</script>

@endsection
