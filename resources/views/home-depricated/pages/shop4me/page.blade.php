@extends('home.layout')
@section('title', 'SHOP4ME')

@section('content')

<br>
<br>
<br>
<br>

<div class="container py-4">
  <!-- <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">SHOP4ME</h4>
  </div>
  <p class="text-muted">Store: <code>{{ $store_slug }}</code></p> -->

  @if(!empty($service))
  <div class="product-box style-4 mb-3" style="background-image: url('{{ $service->background_image_path ? asset('storage/'.$service->background_image_path) : asset('home/images/shop/large/product1.png') }}');">
    <div class="product-content">
      <div class="main-content text-white" style="background: rgba(0,0,0,0.5); padding: 16px; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.3);">
        <h2 class="product-name mb-2" style="color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">{{ $service->title }}</h2>
        <p class="mb-0" style="color: #f1f1f1; text-shadow: 0 1px 2px rgba(0,0,0,0.25);">{{ $service->description }}</p>
      </div>
    </div>
    
  </div>
  <div class="text-center">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#shop4meModal">Create a shopping list</button>
  </div>
  @else
  <div class="card mb-3">
    <div class="card-body">
      <p class="mb-2">Add items from our catalog or enter custom groceries you want us to source and deliver.</p>
      <p class="mb-0">After submission, you'll register/verify your email, provide delivery info, then proceed to payment.</p>
    </div>
  </div>
  @endif

  <!-- Submit List Modal -->
  <div class="modal fade" id="shop4meModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Submit Order List</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form id="shop4meForm">
          <div class="modal-body">
            <div id="shop4meErrors" class="alert alert-danger d-none" role="alert"></div>
            <div id="itemRows">
              <div class="item-row item-card border rounded p-3 mb-2 position-relative">
                <button type="button" class="btn btn-sm btn-link text-danger position-absolute top-0 end-0 remove-row" aria-label="Remove" title="Remove" style="text-decoration:none">&times;</button>
                <div class="row g-2 align-items-end">
                  <div class="col-12 col-md-4">
                    <label class="form-label">Item Name</label>
                    <input type="text" class="form-control" name="items[0][name]" placeholder="e.g. Egusi seeds" required>
                  </div>
                  <div class="col-6 col-md-2">
                    <label class="form-label">Qty</label>
                    <input type="number" step="0.01" class="form-control" name="items[0][qty]" value="1">
                  </div>
                  <div class="col-6 col-md-2">
                    <label class="form-label">Unit</label>
                    <input type="text" class="form-control" name="items[0][unit_hint]" placeholder="kg/pack">
                  </div>
                  <div class="col-6 col-md-2">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" min="0" class="form-control item-amount" name="items[0][amount_hint]" value="0">
                  </div>
                  <div class="col-6 col-md-2">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" name="items[0][notes]" placeholder="optional">
                  </div>
                </div>
              </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <button id="addItemBtn" class="btn btn-outline-secondary btn-sm" type="button">Add new item</button>
              <div class="text-muted small">Budget updates automatically from item amounts</div>
            </div>
            <div class="row g-2 mt-2">
              <div class="col-md-4">
                <label class="form-label">Budget Amount (auto)</label>
                <input id="budgetTotal" type="number" step="0.01" class="form-control" name="budget_amount" value="0" readonly>
              </div>
              <div class="col-md-8">
                <label class="form-label">Notes</label>
                <input type="text" class="form-control" name="notes">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Proceed</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const container = document.getElementById('itemRows');
  function addRow(){
    const idx = container.querySelectorAll('.item-row').length;
    const tpl = `
      <div class=\"item-row item-card border rounded p-3 mb-2 position-relative\">
        <button type=\"button\" class=\"btn btn-sm btn-link text-danger position-absolute top-0 end-0 remove-row\" aria-label=\"Remove\" title=\"Remove\" style=\"text-decoration:none\">&times;</button>
        <div class=\"row g-2 align-items-end\">
          <div class=\"col-12 col-md-4\">
            <label class=\"form-label\">Item Name</label>
            <input type=\"text\" class=\"form-control\" name=\"items[${idx}][name]\" placeholder=\"e.g. Egusi seeds\" required>
          </div>
          <div class=\"col-6 col-md-2\">
            <label class=\"form-label\">Qty</label>
            <input type=\"number\" step=\"0.01\" class=\"form-control\" name=\"items[${idx}][qty]\" value=\"1\">
          </div>
          <div class=\"col-6 col-md-2\">
            <label class=\"form-label\">Unit</label>
            <input type=\"text\" class=\"form-control\" name=\"items[${idx}][unit_hint]\" placeholder=\"kg/pack\">
          </div>
          <div class=\"col-6 col-md-2\">
            <label class=\"form-label\">Amount</label>
            <input type=\"number\" step=\"0.01\" min=\"0\" class=\"form-control item-amount\" name=\"items[${idx}][amount_hint]\" value=\"0\">
          </div>
          <div class=\"col-6 col-md-2\">
            <label class=\"form-label\">Notes</label>
            <input type=\"text\" class=\"form-control\" name=\"items[${idx}][notes]\" placeholder=\"optional\">
          </div>
        </div>
      </div>`;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = tpl;
    container.appendChild(wrapper.firstElementChild);
    recalc();
  }
  function recalc(){
    let sum = 0;
    container.querySelectorAll('.item-amount').forEach(inp=>{
      const v = parseFloat(inp.value || '0');
      if(!isNaN(v)) sum += v;
    });
    const budget = document.getElementById('budgetTotal');
    budget.value = sum.toFixed(2);
  }
  document.getElementById('addItemBtn').addEventListener('click', addRow);
  container.addEventListener('click', function(e){
    if(e.target.closest('.remove-row')) {
      const rows = container.querySelectorAll('.item-row');
      if(rows.length > 1) {
        e.target.closest('.item-row').remove();
        recalc();
      }
    }
  });
  container.addEventListener('input', function(e){
    if(e.target.classList.contains('item-amount')) recalc();
  });
  const errBox = document.getElementById('shop4meErrors');
  function showErrors(errors){
    if(!errors) { errBox.classList.add('d-none'); errBox.innerHTML = ''; return; }
    const lines = [];
    Object.keys(errors).forEach(k=>{
      const msgs = Array.isArray(errors[k]) ? errors[k] : [errors[k]];
      msgs.forEach(m=>lines.push(`<div>${m}</div>`));
    });
    errBox.innerHTML = lines.join('');
    errBox.classList.remove('d-none');
  }

  document.getElementById('shop4meForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const fd = new FormData(this);
    const payload = { items: [] };
    fd.forEach((v,k)=>{
      if(k.startsWith('items[')){
        const m = k.match(/^items\[(\d+)\]\[(\w+)\]$/);
        if(m){ const i = parseInt(m[1]); payload.items[i] = payload.items[i]||{}; payload.items[i][m[2]] = v; }
      } else if(k==='budget_amount' || k==='notes') { payload[k]=v; }
    });
    // Ensure budget equals computed sum client-side
    payload.budget_amount = document.getElementById('budgetTotal').value;
    try{
      const res = await fetch('{{ route('shop4me.requests.store', ['store_slug' => $store_slug]) }}', { method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json','Content-Type':'application/json'}, body: JSON.stringify(payload)});
      const data = await res.json();
      if(!res.ok){
        showErrors(data.errors || {'error':'Submission failed'});
        return;
      }
      showErrors(null);
      if(data.next){
        window.location.href = data.next;
      }
    }catch(err){ console.error(err); }
  });
})();
</script>
@endsection
