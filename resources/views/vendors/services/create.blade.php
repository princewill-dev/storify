@extends('vendors.layout')
@section('subtitle', 'Create service')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Create service</h4>
    <a href="{{ route('vendor.services.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('vendor.services.store', ['vendor' => $vendor]) }}" enctype="multipart/form-data">
        @csrf
        <div class="row g-3">
          <div class="col-md-6">
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

          <div class="col-md-6">
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

          <div class="col-12">
            <label class="form-label">Description</label>
            <input id="service-description" type="hidden" name="description" value="{{ old('description') }}">
            <trix-editor input="service-description" class="form-control"></trix-editor>
          </div>

          <div class="col-12">
            <label class="form-label">Images</label>
            <input type="file" name="images[]" class="form-control" accept="image/*" multiple id="images-input">
            <input type="hidden" name="primary_image" id="primary-image-index" value="0">
            <div class="mt-2" id="primary-picker" style="display:none;">
              <div class="fw-bold">Choose primary image</div>
              <div id="primary-options" class="d-flex flex-wrap gap-3"></div>
            </div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Create</button>
          <a class="btn btn-light" href="{{ route('vendor.services.index', ['vendor' => $vendor]) }}">Cancel</a>
        </div>
      </form>
    </div>
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
      wrapper.className = 'd-inline-flex align-items-center gap-2 border rounded p-2';
      wrapper.innerHTML = `<input type="radio" name="_primary_pick" ${idx===0?'checked':''} value="${idx}" id="${id}"><span>${f.name}</span>`;
      wrapper.querySelector('input').addEventListener('change', ()=>{ hidden.value = String(idx); });
      options.appendChild(wrapper);
    });
    hidden.value = '0';
  });
})();
</script>
@endsection
