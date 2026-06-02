@extends('management.layout')
@section('subtitle', 'Receive Inventory · ' . $warehouse->name)

@push('styles')
<style>
    .receive-page { display: flex; flex-direction: column; height: calc(100vh - 120px); }
    .receive-toolbar { flex-shrink: 0; }
    .receive-body { flex: 1; display: flex; overflow: hidden; min-height: 0; }
    .receive-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
    .receive-sidebar { width: 340px; flex-shrink: 0; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; }
    .receive-sidebar-body { flex: 1; overflow-y: auto; padding: 1.25rem; }
    .receive-sidebar-footer { flex-shrink: 0; border-top: 1px solid #e2e8f0; padding: 1rem; }
    .product-pick-card { cursor: pointer; transition: all 0.12s; }
    .product-pick-card:hover { border-color: #6366f1; box-shadow: 0 2px 8px rgba(99,102,241,0.15); }
    .product-pick-card.selected { border-color: #6366f1; background: #eef2ff; }
    .qty-badge { transition: all 0.1s; }
    @media (max-width: 1024px) {
        .receive-body { flex-direction: column; }
        .receive-sidebar { width: 100%; max-height: 45vh; border-left: none; border-top: 1px solid #e2e8f0; }
    }
</style>
@endpush

@section('content')
<div class="receive-page -m-6">
    <div class="receive-toolbar flex items-center gap-3 px-4 py-2.5 bg-white border-b flex-wrap">
        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Back
        </a>
        <div class="h-4 w-px bg-slate-200"></div>
        <span class="text-sm font-semibold text-slate-800">Receive into {{ $warehouse->name }}</span>
        <div class="flex-1"></div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-400">From:</span>
            <select id="sourceWarehouse" onchange="onSourceChange(this.value)" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-1.5 min-w-[220px]">
                <option value="">Select source warehouse</option>
                @foreach($warehouses as $wh)
                <option value="{{ $wh->warehouse_code }}" data-id="{{ $wh->id }}">{{ $wh->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="receive-body">
        <div class="receive-main">
            <div id="noSourceState" class="flex-1 flex items-center justify-center">
                <div class="text-center">
                    <span class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 mb-4"><i class="fi fi-rr-warehouse-alt text-2xl"></i></span>
                    <h3 class="text-base font-semibold text-slate-700 mb-1">Select a source warehouse</h3>
                    <p class="text-sm text-slate-400">Choose which warehouse you're requesting inventory from</p>
                </div>
            </div>

            <div id="pickArea" class="hidden flex-1 flex flex-col overflow-hidden">
                <div class="px-4 py-3 bg-white border-b space-y-3">
                    <input type="text" id="productSearch" class="w-full rounded-lg border-slate-300 px-4 py-2.5 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm" placeholder="Search products by name..." autofocus oninput="filterProducts(this.value)">
                    <div class="flex items-center gap-2 text-xs text-slate-400">
                        <span id="resultCount">0 products available</span>
                        <span class="mx-1">·</span>
                        <span>Click a product to add it to your request</span>
                    </div>
                </div>
                <div id="productGrid" class="flex-1 overflow-y-auto p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3"></div>
            </div>
        </div>

        <div class="receive-sidebar">
            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800">Request Items</h3>
                <button onclick="clearAll()" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Clear all</button>
            </div>
            <div id="cartItems" class="receive-sidebar-body">
                <div class="text-center text-slate-400 py-8">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-cube"></i></span>
                    <p class="text-sm">No items selected</p>
                    <p class="text-xs mt-0.5">Search and click products to add</p>
                </div>
            </div>
            <form id="receiveForm" method="POST" action="{{ route('management.warehouses.receive.store', $warehouse) }}" class="receive-sidebar-footer">
                @csrf
                <input type="hidden" name="from_warehouse_id" id="hiddenSourceId">
                <div id="itemsContainer"></div>
                <div class="flex justify-between text-sm mb-1"><span class="text-slate-500">Items</span><span id="cartItemCount" class="font-semibold text-slate-700">0</span></div>
                <div class="flex justify-between text-sm mb-1"><span class="text-slate-500">Total Units</span><span id="cartTotalUnits" class="font-semibold text-slate-700">0</span></div>
                <div class="space-y-1.5 mt-3">
                    <label class="block text-xs font-medium text-slate-500">Notes (optional)</label>
                    <textarea name="notes" class="w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm" rows="2" placeholder="Add a note..."></textarea>
                </div>
                <button type="submit" id="submitBtn" disabled class="w-full mt-3 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fi fi-rr-truck-loading mr-1.5"></i> Request Transfer
                </button>
            </form>
        </div>
    </div>
</div>

@if(session('error'))
<div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition class="fixed top-4 right-4 z-50 max-w-sm w-full bg-red-50 border border-red-200 text-red-800 rounded-xl shadow-lg p-4">
    <div class="flex items-start gap-3"><i class="fi fi-rr-exclamation text-red-500 text-lg mt-0.5"></i><div class="flex-1 text-sm font-medium">{{ session('error') }}</div><button @click="show = false" class="text-red-400 hover:text-red-600">&times;</button></div>
</div>
@endif

<script>
let cart = {};
let allProducts = [];

function onSourceChange(warehouseCode) {
    document.getElementById('hiddenSourceId').value = '';
    document.getElementById('noSourceState').classList.remove('hidden');
    document.getElementById('pickArea').classList.add('hidden');
    cart = {};
    allProducts = [];
    renderCart();
    if (!warehouseCode) return;

    const url = `/management/warehouses/${warehouseCode}/products-json`;

    fetch(url, { headers: { 'Accept': 'application/json' } })
    .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}: ${r.statusText}`);
        return r.json();
    })
    .then(d => {
        allProducts = d.products || [];
        document.getElementById('resultCount').textContent = allProducts.length + ' products available';
        document.getElementById('noSourceState').classList.add('hidden');
        document.getElementById('pickArea').classList.remove('hidden');
        renderProducts('');
    })
    .catch(e => {
        document.getElementById('resultCount').textContent = 'Could not load products — ' + e.message;
        allProducts = [];
        renderProducts('');
    });
}

function renderProducts(query) {
    const q = query.toLowerCase();
    const filtered = allProducts.filter(p => p.name.toLowerCase().includes(q));
    const grid = document.getElementById('productGrid');
    document.getElementById('resultCount').textContent = filtered.length + ' products available';

    grid.innerHTML = filtered.map(p => {
        const inCart = cart[p.product_id];
        const isSelected = inCart && inCart.quantity > 0;
        const cls = isSelected ? 'selected' : '';
        const badge = isSelected
            ? `<span class="qty-badge inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">${inCart.quantity} added</span>`
            : '';

        return `<div class="product-pick-card bg-white rounded-xl border ${cls} p-3 shadow-sm" onclick="toggleProduct(${p.product_id}, '${p.name.replace(/'/g, "\\'")}', ${p.available})">
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800 truncate">${p.name}</p>
                    <p class="text-xs text-slate-400 mt-0.5">${p.available} units available</p>
                </div>
                ${badge}
            </div>
            ${isSelected ? `
            <div class="flex items-center gap-2 mt-2 pt-2 border-t border-slate-100" onclick="event.stopPropagation()">
                <button class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors" onclick="adjustCart(${p.product_id}, '${p.name.replace(/'/g, "\\'")}', -1, ${p.available})">−</button>
                <input type="number" value="${inCart.quantity}" min="1" max="${p.available}" class="w-16 text-center rounded-lg border-slate-200 text-sm py-1 focus:border-indigo-500 focus:ring-indigo-500" onchange="setCartQty(${p.product_id}, '${p.name.replace(/'/g, "\\'")}', this.value, ${p.available})" onclick="event.stopPropagation()">
                <button class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors" onclick="adjustCart(${p.product_id}, '${p.name.replace(/'/g, "\\'")}', 1, ${p.available})">+</button>
                <button class="ml-auto text-[10px] text-slate-400 hover:text-red-500" onclick="removeFromCart(${p.product_id}); renderProducts('${query}');">Remove</button>
            </div>` : ''}
        </div>`;
    }).join('');
}

function toggleProduct(id, name, max) {
    if (cart[id]) {
        removeFromCart(id);
    } else {
        cart[id] = { product_id: id, name: name, quantity: 1, maxQty: max };
    }
    refreshAll();
}

function adjustCart(id, name, delta, max) {
    if (!cart[id]) return;
    cart[id].quantity = Math.max(1, Math.min(max, cart[id].quantity + delta));
    refreshAll();
}

function setCartQty(id, name, val, max) {
    const q = parseInt(val);
    if (isNaN(q) || q < 1) { cart[id].quantity = 1; }
    else if (q > max) { cart[id].quantity = max; }
    else { cart[id].quantity = q; }
    refreshAll();
}

function removeFromCart(id) { delete cart[id]; refreshAll(); }
function clearAll() { cart = {}; refreshAll(); }

function refreshAll() {
    renderProducts(document.getElementById('productSearch')?.value || '');
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const itemsContainer = document.getElementById('itemsContainer');
    const entries = Object.values(cart);
    let totalUnits = 0;

    itemsContainer.innerHTML = entries.map((item, i) => {
        totalUnits += item.quantity;
        return `<input type="hidden" name="items[${i}][product_id]" value="${item.product_id}">
                <input type="hidden" name="items[${i}][quantity]" value="${item.quantity}">`;
    }).join('');

    document.getElementById('cartItemCount').textContent = entries.length;
    document.getElementById('cartTotalUnits').textContent = totalUnits;
    document.getElementById('submitBtn').disabled = entries.length === 0;

    if (entries.length === 0) {
        container.innerHTML = `<div class="text-center text-slate-400 py-8">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-cube"></i></span>
            <p class="text-sm">No items selected</p>
            <p class="text-xs mt-0.5">Search and click products to add</p></div>`;
    } else {
        container.innerHTML = entries.map(item => `
            <div class="flex items-center justify-between py-2 border-b border-slate-50">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-800 truncate">${item.name}</p>
                    <div class="flex items-center gap-2 mt-1">
                        <button class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="adjustCart(${item.product_id}, '', -1, ${item.maxQty})">−</button>
                        <span class="text-xs font-medium text-slate-600">${item.quantity}</span>
                        <button class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="adjustCart(${item.product_id}, '', 1, ${item.maxQty})">+</button>
                    </div>
                </div>
                <button class="text-[10px] text-slate-400 hover:text-red-500 ml-2" onclick="removeFromCart(${item.product_id})">Remove</button>
            </div>`).join('');
    }
}

function filterProducts(query) { renderProducts(query); }
</script>
@endsection
