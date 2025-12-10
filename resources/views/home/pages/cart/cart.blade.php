@extends('home.layout')
@section('title', 'Cart')

@section('content')


<div class="page-content">
    <!--banner-->
    <div class="dz-bnr-inr" style="background-image:url({{asset('home/images/background/bg-shape.jpg')}});">
        <div class="container">
            <div class="dz-bnr-inr-entry">
                <h1>Cart</h1>
                <nav aria-label="breadcrumb" class="breadcrumb-row">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('home.index') }}"> Home</a></li>
                        @isset($store)
                        <li class="breadcrumb-item"><a href="{{ route('home.store.products.index', ['store_slug' => $store->slug]) }}">{{ $store->name }}</a></li>
                        @endisset
                        <li class="breadcrumb-item">Cart</li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    
    <!-- contact area -->
    <section class="content-inner shop-account">
        <!-- Product -->
        <div class="container">
            <div class="row">
                <div class="col-lg-8">
                    <div class="table-responsive">
                        <table class="table check-tbl">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th></th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="js-cart-tbody">
                                <tr><td colspan="6" class="text-center text-muted py-5">Loading cart…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="row shop-form m-t30">
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="input-group mb-0">
                                    <input name="dzEmail" required="required" type="text" class="form-control" placeholder="Coupon Code">
                                    <div class="input-group-addon">
                                        <button name="submit" value="Submit" type="submit" class="btn coupon">
                                            Apply Coupon
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <a href="shop-cart.html" class="btn btn-grey">UPDATE CART</a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <h4 class="title mb15">Cart Total</h4>
                    <div class="cart-detail">
                        @if(!empty($store))
                        <div class="card m-b15">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 m-b15">
                                    @if(!empty($store->logo_path))
                                        <img src="{{ asset('storage/'.$store->logo_path) }}" alt="{{ $store->name }}" style="width:48px;height:48px;object-fit:cover;border-radius:8px;">
                                    @endif
                                    <div class="d-flex flex-column">
                                        <span class="text-muted small">Sold by</span>
                                        <h6 class="mb-0">{{ $store->name }}</h6>
                                    </div>
                                </div>
                                <ul class="list-unstyled mb-2">
                                    @if(!empty($store->support_email))
                                        <li class="mb-1"><i class="fa fa-envelope me-2 text-primary"></i>{{ $store->support_email }}</li>
                                    @endif
                                    @if(!empty($store->support_phone))
                                        <li class="mb-1"><i class="fa fa-phone me-2 text-primary"></i>{{ $store->support_phone }}</li>
                                    @endif
                                    @if(!empty($store->address))
                                        <li class="mb-1"><i class="fa fa-map-marker-alt me-2 text-primary"></i>{{ $store->address }}</li>
                                    @endif
                                </ul>
                                @if(!empty($store->instagram_url) || !empty($store->facebook_url) || !empty($store->twitter_url) || !empty($store->tiktok_url))
                                    <div class="d-flex align-items-center gap-3">
                                        @if(!empty($store->instagram_url))
                                            <a href="{{ $store->instagram_url }}" target="_blank" class="text-secondary"><i class="fa-brands fa-instagram"></i></a>
                                        @endif
                                        @if(!empty($store->facebook_url))
                                            <a href="{{ $store->facebook_url }}" target="_blank" class="text-secondary"><i class="fa-brands fa-facebook"></i></a>
                                        @endif
                                        @if(!empty($store->twitter_url))
                                            <a href="{{ $store->twitter_url }}" target="_blank" class="text-secondary"><i class="fa-brands fa-twitter"></i></a>
                                        @endif
                                        @if(!empty($store->tiktok_url))
                                            <a href="{{ $store->tiktok_url }}" target="_blank" class="text-secondary"><i class="fa-brands fa-tiktok"></i></a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                        @endif

                        <div class="card m-b30">
                            <div class="card-body">
                                <h6 class="dz-title mb-3">Choose your location</h6>
                                <div class="row g-2">
                                    <div class="col-12">
                                        <select id="stateSelect" class="form-select">
                                            @foreach(($states ?? []) as $st)
                                                <option value="{{ $st }}">{{ $st }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <select id="areaSelect" class="form-select">
                                            <option value="" disabled selected>Select area</option>
                                        </select>
                                    </div>
                                    <div class="col-12" id="deliveryInfo" style="display:none;">
                                        <div class="d-flex justify-content-between small">
                                            <span>Price:</span>
                                            <span id="deliveryPrice">—</span>
                                        </div>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span>VAT ({{ (string)($vatPercentage ?? 0) }}%):</span>
                                            <span id="deliveryVat">—</span>
                                        </div>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span>Delivery fee:</span>
                                            <span id="deliveryFee">—</span>
                                        </div>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span>Delivery window:</span>
                                            <span id="deliveryDays">—</span>
                                        </div>
                                        <div class="d-flex justify-content-between fw-semibold mt-1">
                                            <span>Total:</span>
                                            <span id="deliveryTotal">—</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        {{-- Delivery data for JS --}}
                        <script type="application/json" id="cart-areas-json">@json($areasByState ?? [])</script>
                        <span id="cart-vat-pct" data-vat="{{ (float)($vatPercentage ?? 0) }}" hidden></span>
                        <table>
                            <tbody>
                                <tr class="total">
                                    <td>
                                        <h6 class="mb-0">Total</h6>
                                    </td>
                                    <td class="price">
                                        <span id="js-cart-total-main">₦0.00</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <a href="{{ route('checkout.index', ['store_slug' => $store->slug]) }}" class="btn btn-secondary w-100">PLACE ORDER</a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Product END -->
    </section>
    <!-- contact area End--> 
    

</div>


<script>
    (function(){
    function fmtNgn(kobo){ var n=(kobo||0)/100; return new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(n); }
    function renderCartTable(data){
        var tbody=document.getElementById('js-cart-tbody');
        var totalEl=document.getElementById('js-cart-total-main');
        if (totalEl) totalEl.textContent = fmtNgn(data.total||0);
        // expose subtotal for delivery calculations (kobo)
        window.__cartSubtotalKobo = parseInt(String(data.subtotal||0),10) || 0;
        if (typeof window.__updateDeliveryInfo === 'function'){
          try { window.__updateDeliveryInfo(); } catch(_){}
        }
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!data.items || !data.items.length){
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Your cart is empty</td></tr>';
        return;
        }
        data.items.forEach(function(it){
        var img = it.image ? ('/storage/'+it.image) : '';
        var imgTd = img ? '<td class="product-item-img"><img src="'+img+'" alt=""></td>' : '<td class="product-item-img"></td>';
        var tr=document.createElement('tr');
        tr.innerHTML = imgTd+'\n'
            + '<td class="product-item-name">'+(it.name||'')+'</td>\n'
            + '<td class="product-item-price">'+fmtNgn(it.unit_amount||0)+'</td>\n'
            + '<td class="product-item-quantity"><div class="quantity btn-quantity style-1 me-3">'
            +   '<input type="number" min="1" value="'+(it.qty||1)+'" class="js-cart-qty" data-item-id="'+it.id+'">'
            + '</div></td>\n'
            + '<td class="product-item-totle">'+fmtNgn(it.line_subtotal||0)+'</td>\n'
            + '<td class="product-item-close"><a href="#" class="js-cart-remove" data-remove-item="'+it.id+'"><i class="fa fa-times"></i></a></td>';
        tbody.appendChild(tr);
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        if (window.CartAPI){
        window.CartAPI.fetchCart();
        // Also refresh table after fetch by hooking render in header
        var origRender = window.renderCart;
        }
        // Fallback direct fetch
        var slug = (location.pathname||'/').split('/').filter(Boolean)[0]||'';
        if (!window.CartAPI){
        fetch('/'+slug+'/cart/json', {credentials:'same-origin'})
            .then(r=>r.json()).then(function(data){ renderCartTable(data); }).catch(()=>{});
        }
        // Delegate events for qty and remove using header handlers
        document.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('js-cart-qty')){
            var id=e.target.getAttribute('data-item-id');
            var v=Math.max(1, parseInt(e.target.value||'1',10));
            e.target.value=v;
            if (window.CartAPI){ window.CartAPI.updateQty(id, v); setTimeout(function(){ window.CartAPI.fetchCart(); }, 200); }
        }
        });
        document.addEventListener('click', function(e){
        var t=e.target.closest('.js-cart-remove');
        if (!t) return;
        e.preventDefault();
        var id=t.getAttribute('data-remove-item');
        if (window.CartAPI){ window.CartAPI.removeItem(id); setTimeout(function(){ window.CartAPI.fetchCart(); }, 200); }
        });
        // Initial page table fill
        setTimeout(function(){
        var slug2=(location.pathname||'/').split('/').filter(Boolean)[0]||'';
        fetch('/'+slug2+'/cart/json', {credentials:'same-origin'})
            .then(r=>r.json()).then(renderCartTable).catch(()=>{});
        }, 50);
    });
    // Delivery area + VAT logic
    (function(){
        var areas = (function(){ try { return JSON.parse(document.getElementById('cart-areas-json')?.textContent||'{}'); } catch(_) { return {}; } })();
        var vatPct = (function(){ var el=document.getElementById('cart-vat-pct'); return el?parseFloat(el.getAttribute('data-vat')||'0'):0; })();
        var stateSel = document.getElementById('stateSelect');
        var areaSel = document.getElementById('areaSelect');
        var infoWrap = document.getElementById('deliveryInfo');
        var feeEl = document.getElementById('deliveryFee');
        var daysEl = document.getElementById('deliveryDays');
        var totalEl2 = document.getElementById('deliveryTotal');
        var priceEl = document.getElementById('deliveryPrice');
        var vatEl = document.getElementById('deliveryVat');

        function ensureStates(){
          if (!stateSel) return;
          if (stateSel.options.length > 0) return; // already server-filled
          var keys = Object.keys(areas||{});
          stateSel.innerHTML = '';
          keys.forEach(function(k){ var opt=document.createElement('option'); opt.value=k; opt.textContent=k; stateSel.appendChild(opt); });
        }
        function refreshAreas(){
          if (!stateSel || !areaSel) return;
          var st = stateSel.value || '';
          var list = (st && areas[st]) ? areas[st] : [];
          areaSel.innerHTML = '';
          if (!list.length){
            var opt0=document.createElement('option'); opt0.value=''; opt0.textContent='No areas available'; opt0.disabled=true; opt0.selected=true; areaSel.appendChild(opt0);
            if (infoWrap) infoWrap.style.display='none';
            // keep main total equal to subtotal when no delivery
            var subtotal = parseInt(String(window.__cartSubtotalKobo||0),10) || 0;
            var mainTotal = document.getElementById('js-cart-total-main');
            if (mainTotal) mainTotal.textContent = fmtNgn(subtotal);
            return;
          }
          list.forEach(function(a){ var opt=document.createElement('option'); opt.value=a.id; opt.textContent=a.area; opt.setAttribute('data-fee', a.fee||0); opt.setAttribute('data-days', a.days||'-'); areaSel.appendChild(opt); });
          areaSel.selectedIndex=0;
          updateDeliveryInfo();
        }
        function updateDeliveryInfo(){
          if (!areaSel){ return; }
          var opt = areaSel.options[areaSel.selectedIndex];
          var feeKobo = opt ? (parseInt(opt.getAttribute('data-fee')||'0',10)||0) : 0;
          var days = opt ? (opt.getAttribute('data-days')||'—') : '—';
          var subtotal = parseInt(String(window.__cartSubtotalKobo||0),10) || 0;
          var vat = Math.round((subtotal * (vatPct||0)) / 100);
          var total = subtotal + vat + feeKobo;
          if (priceEl) priceEl.textContent = fmtNgn(subtotal);
          if (vatEl) vatEl.textContent = fmtNgn(vat);
          if (feeEl) feeEl.textContent = fmtNgn(feeKobo);
          if (daysEl) daysEl.textContent = days + ' day' + (String(days)==='1'?'':'s');
          if (totalEl2) totalEl2.textContent = fmtNgn(total);
          var mainTotal = document.getElementById('js-cart-total-main');
          if (mainTotal) mainTotal.textContent = fmtNgn(total);
          if (infoWrap) infoWrap.style.display='';
        }
        window.__updateDeliveryInfo = updateDeliveryInfo;
        // bootstrap
        ensureStates();
        if (stateSel && areaSel){
          stateSel.addEventListener('change', refreshAreas);
          areaSel.addEventListener('change', updateDeliveryInfo);
          if (stateSel.options.length>0){ refreshAreas(); }
        }
    })();
    })();
</script>

@endsection