@extends('management.layout')
@section('subtitle', 'Send Inventory · ' . $warehouse->name)

@push('styles')
<style>
    .send-page { display: flex; flex-direction: column; height: calc(100vh - 120px); }
    .send-toolbar { flex-shrink: 0; }
    .send-body { flex: 1; display: flex; overflow: hidden; min-height: 0; }
    .send-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
    .send-sidebar { width: 340px; flex-shrink: 0; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; }
    .send-sidebar-body { flex: 1; overflow-y: auto; padding: 1.25rem; }
    .send-sidebar-footer { flex-shrink: 0; border-top: 1px solid #e2e8f0; padding: 1rem; }
    .product-pick-card { cursor: pointer; transition: all 0.12s; }
    .product-pick-card:hover { border-color: #6366f1; box-shadow: 0 2px 8px rgba(99,102,241,0.15); }
    .product-pick-card.selected { border-color: #6366f1; background: #eef2ff; }
    @media (max-width: 1024px) {
        .send-body { flex-direction: column; }
        .send-sidebar { width: 100%; max-height: 45vh; border-left: none; border-top: 1px solid #e2e8f0; }
    }
</style>
@endpush

@section('content')
<div class="send-page -m-6">
    <div class="send-toolbar flex items-center gap-3 px-4 py-2.5 bg-white border-b flex-wrap">
        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Back
        </a>
        <div class="h-4 w-px bg-slate-200"></div>
        <span class="text-sm font-semibold text-slate-800">Send from {{ $warehouse->name }}</span>
        <div class="flex-1"></div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-400">To:</span>
            <select id="destWarehouse" onchange="updateDest()" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-1.5 min-w-[220px]">
                <option value="">Select destination</option>
                @foreach($warehouses as $wh)
                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="send-body">
        <div class="send-main">
            <div class="px-4 py-3 bg-white border-b space-y-3">
                <input type="text" id="productSearch" class="w-full rounded-lg border-slate-300 px-4 py-2.5 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm" placeholder="Search this warehouse's inventory..." autofocus oninput="filterProducts(this.value)">
                <div class="flex items-center gap-2 text-xs text-slate-400">
                    <span>{{ $stockLocations->count() }} products available in {{ $warehouse->name }}</span>
                    <span class="mx-1">·</span>
                    <span>Click a product to add it to your transfer</span>
                </div>
            </div>
            <div id="productGrid" class="flex-1 overflow-y-auto p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                @foreach($stockLocations as $loc)
                @php $p = $loc->product; @endphp
                @if($p)
                <div class="product-pick-card bg-white rounded-xl border p-3 shadow-sm" data-id="{{ $p->id }}" data-name="{{ $p->name }}" data-max="{{ (int) $loc->quantity }}" onclick="toggleProduct(this)">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate">{{ $p->name }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $loc->quantity }} units available</p>
                        </div>
                        <span class="qty-badge hidden inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700"></span>
                    </div>
                    <div class="cart-controls hidden flex items-center gap-2 mt-2 pt-2 border-t border-slate-100" onclick="event.stopPropagation()">
                        <button class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors" onclick="adjustCart(this.closest('.product-pick-card'), -1)">−</button>
                        <input type="number" value="1" min="1" class="w-16 text-center rounded-lg border-slate-200 text-sm py-1 focus:border-indigo-500 focus:ring-indigo-500" onchange="setCartQty(this.closest('.product-pick-card'), this.value)" onclick="event.stopPropagation()">
                        <button class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors" onclick="adjustCart(this.closest('.product-pick-card'), 1)">+</button>
                        <button class="ml-auto text-[10px] text-slate-400 hover:text-red-500" onclick="removeFromCart(this.closest('.product-pick-card'))">Remove</button>
                    </div>
                </div>
                @endif
                @endforeach
            </div>
        </div>

        <div class="send-sidebar">
            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800">Transfer Items</h3>
                <button onclick="clearAll()" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Clear all</button>
            </div>
            <div id="cartItems" class="send-sidebar-body">
                <div class="text-center text-slate-400 py-8">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-cube"></i></span>
                    <p class="text-sm">No items selected</p>
                    <p class="text-xs mt-0.5">Search and click products to add</p>
                </div>
            </div>
            <form id="sendForm" method="POST" action="{{ route('management.warehouses.send.store', $warehouse) }}" class="send-sidebar-footer">
                @csrf
                <input type="hidden" name="to_warehouse_id" id="hiddenDestId">
                <div id="itemsContainer"></div>
                <div class="flex justify-between text-sm mb-1"><span class="text-slate-500">Items</span><span id="cartItemCount" class="font-semibold text-slate-700">0</span></div>
                <div class="flex justify-between text-sm mb-1"><span class="text-slate-500">Total Units</span><span id="cartTotalUnits" class="font-semibold text-slate-700">0</span></div>
                <div class="space-y-1.5 mt-3">
                    <label class="block text-xs font-medium text-slate-500">Notes (optional)</label>
                    <textarea name="notes" class="w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm" rows="2" placeholder="Add a note..."></textarea>
                </div>
                <button type="submit" id="submitBtn" disabled class="w-full mt-3 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fi fi-rr-paper-plane mr-1.5"></i> Submit Transfer
                </button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleProduct(card) {
    const id = card.dataset.id;
    const isSelected = card.classList.contains('selected');
    if (isSelected) {
        removeFromCart(card);
    } else {
        card.classList.add('selected');
        card.querySelector('.qty-badge').classList.remove('hidden');
        card.querySelector('.cart-controls').classList.remove('hidden');
    }
    renderCart();
}

function adjustCart(card, delta) {
    const input = card.querySelector('.cart-controls input');
    const max = parseInt(card.dataset.max);
    let qty = parseInt(input.value) || 1;
    qty = Math.max(1, Math.min(max, qty + delta));
    input.value = qty;
    card.querySelector('.qty-badge').textContent = qty + ' added';
    renderCart();
}

function setCartQty(card, val) {
    const max = parseInt(card.dataset.max);
    let qty = parseInt(val);
    if (isNaN(qty) || qty < 1) qty = 1;
    else if (qty > max) qty = max;
    card.querySelector('.cart-controls input').value = qty;
    card.querySelector('.qty-badge').textContent = qty + ' added';
    renderCart();
}

function removeFromCart(card) {
    card.classList.remove('selected');
    card.querySelector('.qty-badge').classList.add('hidden');
    card.querySelector('.cart-controls').classList.add('hidden');
    card.querySelector('.qty-badge').textContent = '';
    renderCart();
}

function clearAll() {
    document.querySelectorAll('.product-pick-card.selected').forEach(c => removeFromCart(c));
    renderCart();
}

function updateDest() {
    const sel = document.getElementById('destWarehouse');
    document.getElementById('hiddenDestId').value = sel.value;
}

function renderCart() {
    const selected = document.querySelectorAll('.product-pick-card.selected');
    const container = document.getElementById('cartItems');
    const itemsContainer = document.getElementById('itemsContainer');
    let totalUnits = 0;

    itemsContainer.innerHTML = '';
    selected.forEach((card, i) => {
        const qty = parseInt(card.querySelector('.cart-controls input').value) || 1;
        totalUnits += qty;
        itemsContainer.innerHTML += `<input type="hidden" name="items[${i}][product_id]" value="${card.dataset.id}">
                                     <input type="hidden" name="items[${i}][quantity]" value="${qty}">`;
    });

    document.getElementById('cartItemCount').textContent = selected.length;
    document.getElementById('cartTotalUnits').textContent = totalUnits;
    document.getElementById('submitBtn').disabled = selected.length === 0;

    if (selected.length === 0) {
        container.innerHTML = `<div class="text-center text-slate-400 py-8">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-cube"></i></span>
            <p class="text-sm">No items selected</p></div>`;
    } else {
        container.innerHTML = '';
        selected.forEach(card => {
            const name = card.dataset.name;
            const id = card.dataset.id;
            const max = parseInt(card.dataset.max);
            const qty = parseInt(card.querySelector('.cart-controls input').value) || 1;
            const div = document.createElement('div');
            div.className = 'flex items-center justify-between py-2 border-b border-slate-50';
            div.innerHTML = `<div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 truncate">${name}</p>
                <div class="flex items-center gap-2 mt-1">
                    <button class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="adjustCart(document.querySelector('.product-pick-card[data-id=\\'${id}\\']'), -1); return false;">−</button>
                    <span class="text-xs font-medium text-slate-600">${qty}</span>
                    <button class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="adjustCart(document.querySelector('.product-pick-card[data-id=\\'${id}\\']'), 1); return false;">+</button>
                </div></div>
                <button class="text-[10px] text-slate-400 hover:text-red-500 ml-2" onclick="removeFromCart(document.querySelector('.product-pick-card[data-id=\\'${id}\\']'))">Remove</button>`;
            container.appendChild(div);
        });
    }
}

function filterProducts(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#productGrid .product-pick-card').forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        card.style.display = name.includes(q) ? '' : 'none';
    });
}
</script>
@endpush
