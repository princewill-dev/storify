@extends('management.layout')
@section('subtitle', 'Receive Inventory · ' . $warehouse->name)

@push('styles')
<style>
    .receive-card { transition: all 0.1s; }
    .receive-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .receive-card.selected { border-color: #6366f1; background: #eef2ff; }
</style>
@endpush

@section('content')
<div x-data="receiveTransfer()">
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Back
        </a>
        <div class="h-4 w-px bg-slate-200"></div>
        <span class="text-sm font-semibold text-slate-800">Receive into {{ $warehouse->name }}</span>
        <span class="text-xs text-slate-400">{{ $warehouse->warehouse_code }}</span>
    </div>

    @if(session('success'))
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 mb-4">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 mb-4">
        <ul class="list-disc pl-4">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('management.warehouses.receive.store', $warehouse) }}" id="receiveFormMain">
        @csrf
        <input type="hidden" name="from_warehouse_id" :value="sourceId">
        <template x-for="(item, i) in cart" :key="i">
            <input type="hidden" :name="'items['+i+'][product_id]'" :value="item.product_id">
            <input type="hidden" :name="'items['+i+'][quantity]'" :value="item.quantity">
        </template>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4">
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-slate-500 mb-1">From Warehouse</label>
                    <select x-model="sourceCode" @change="onSourceChange()" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2">
                        <option value="">Select source warehouse</option>
                        @foreach($warehouses as $wh)
                        <option value="{{ $wh->warehouse_code }}" data-id="{{ $wh->id }}">{{ $wh->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1">
                    <input type="text" name="notes" x-model="notes" placeholder="Optional note..." class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2">
                </div>
                <button type="submit" :disabled="cart.length === 0 || !sourceId" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0">
                    <i class="fi fi-rr-truck-loading text-xs"></i> Request
                </button>
            </div>
        </div>

        {{-- Empty State: No source selected --}}
        <div x-show="!sourceId" class="bg-white rounded-xl border border-slate-200 p-12 text-center">
            <span class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 mb-4"><i class="fi fi-rr-warehouse-alt text-2xl"></i></span>
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Select a source warehouse</h3>
            <p class="text-xs text-slate-400">Choose which warehouse you're requesting inventory from</p>
        </div>

        {{-- Loading State --}}
        <div x-show="loading" class="bg-white rounded-xl border border-slate-200 p-12 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-spinner animate-spin text-lg"></i></span>
            <p class="text-sm text-slate-500">Loading products...</p>
        </div>

        {{-- No products --}}
        <div x-show="sourceId && !loading && allProducts.length === 0 && !fetchError" class="bg-white rounded-xl border border-slate-200 p-12 text-center" x-cloak>
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-cube text-lg"></i></span>
            <h3 class="text-sm font-semibold text-slate-700 mb-1">No stock available</h3>
            <p class="text-xs text-slate-400">The selected warehouse has no products with available stock.</p>
        </div>

        {{-- Fetch error --}}
        <div x-show="fetchError" class="bg-white rounded-xl border border-red-200 p-12 text-center" x-cloak>
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-red-100 text-red-400 mb-3"><i class="fi fi-rr-exclamation text-lg"></i></span>
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Could not load products</h3>
            <p class="text-xs text-slate-400" x-text="fetchError"></p>
            <button @click="onSourceChange()" class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Retry</button>
        </div>

        {{-- Product Grid --}}
        <div x-show="sourceId && !loading && allProducts.length > 0" x-cloak>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <input type="text" placeholder="Search products..." x-model="searchQuery" @input.debounce.150ms="filter()" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 px-3.5 py-2 w-64">
                    <span class="text-xs text-slate-400" x-text="filteredProducts.length + ' available'"></span>
                </div>
                <div class="flex items-center gap-2" x-show="selectedIds.length > 0">
                    <span class="text-xs font-medium text-slate-600" x-text="selectedIds.length + ' selected'"></span>
                    <button type="button" @click="selectAllToggle()" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium" x-text="selectedIds.length === allProductIds.length ? 'Deselect All' : 'Select All'"></button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3" id="receiveGrid">
                <template x-for="p in filteredProducts" :key="p.product_id">
                    <div class="receive-card bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm"
                         :class="selectedIds.includes(p.product_id.toString()) ? 'selected' : ''"
                         x-show="visiblePids.includes(p.product_id.toString())">
                        <div class="p-3">
                            <div class="flex items-start justify-between mb-2">
                                <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                                    <input type="checkbox" :value="p.product_id.toString()" x-model="selectedIds" @change="onCheckChange(p)" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4">
                                </label>
                            </div>

                            <div class="flex items-start gap-3">
                                <div class="w-14 h-14 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
                                    <a :href="productUrl(p)" class="w-full h-full flex items-center justify-center">
                                        <i class="fi fi-rr-cube text-slate-300 text-lg"></i>
                                    </a>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <a :href="productUrl(p)" class="text-sm font-semibold text-slate-800 hover:text-indigo-600 transition-colors truncate block" x-text="p.name"></a>
                                    <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-50">
                                        <span class="text-xs font-medium text-slate-500" x-text="p.available + ' available'"></span>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium" :class="p.available > 10 ? 'bg-emerald-50 text-emerald-700' : (p.available > 0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700')" x-text="p.available + ' units'"></span>
                                    </div>
                                </div>
                            </div>

                            <div x-show="selectedIds.includes(p.product_id.toString())" x-transition class="flex items-center gap-2 mt-3 pt-2 border-t border-slate-100">
                                <button type="button" @click="adjustQty(p, -1)" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">−</button>
                                <input type="number" :value="getQty(p)" min="1" :max="p.available" class="w-20 text-center rounded-lg border-slate-200 text-sm py-1.5 focus:border-indigo-500 focus:ring-indigo-500" @input="setQty(p, $event.target.value)">
                                <button type="button" @click="adjustQty(p, 1)" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">+</button>
                                <button type="button" @click="removeItem(p)" class="ml-auto text-[11px] text-slate-400 hover:text-red-500 font-medium">Remove</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </form>

    <div x-show="cart.length > 0" x-transition class="fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-slate-200 shadow-lg px-6 py-3" x-cloak>
        <div class="max-w-7xl mx-auto flex items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-slate-800" x-text="cart.length + ' items'"></span>
                <span class="text-xs text-slate-400">·</span>
                <span class="text-sm font-semibold text-slate-800" x-text="cart.reduce((s, i) => s + i.quantity, 0) + ' units'"></span>
            </div>
            <div class="flex-1"></div>
            <button type="button" @click="cart = []; selectedIds = []" class="text-xs text-slate-400 hover:text-red-500 font-medium">Clear all</button>
            <button type="button" onclick="document.getElementById('receiveFormMain').submit()" :disabled="cart.length === 0 || !sourceId" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <i class="fi fi-rr-truck-loading text-xs"></i> Request
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function receiveTransfer() {
    return {
        sourceCode: '',
        sourceId: '',
        notes: '',
        searchQuery: '',
        selectedIds: [],
        cart: [],
        allProducts: [],
        loading: false,
        fetchError: '',

        init() {},

        get allProductIds() {
            return this.allProducts.map(p => p.product_id.toString());
        },

        get visiblePids() {
            return this.allProductIds;
        },

        get filteredProducts() {
            const q = this.searchQuery.toLowerCase();
            if (!q) return this.allProducts;
            return this.allProducts.filter(p => p.name.toLowerCase().includes(q));
        },

        productUrl(p) {
            return '/management/products/' + (p.product_code || p.product_id);
        },

        onSourceChange() {
            const el = document.querySelector('[x-model="sourceCode"]');
            const option = el?.selectedOptions?.[0];
            const id = option?.dataset?.id || '';
            this.sourceId = id;
            this.selectedIds = [];
            this.cart = [];
            this.allProducts = [];
            this.fetchError = '';

            if (!this.sourceCode) return;

            this.loading = true;
            const url = `/management/warehouses/${this.sourceCode}/products-json`;

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP ${r.status}: ${r.statusText}`);
                    return r.json();
                })
                .then(d => {
                    this.allProducts = d.products || [];
                    this.loading = false;
                })
                .catch(e => {
                    this.fetchError = e.message;
                    this.loading = false;
                    this.allProducts = [];
                });
        },

        filter() {},

        selectAllToggle() {
            if (this.selectedIds.length === this.allProductIds.length) {
                this.selectedIds = [];
                this.cart = [];
            } else {
                this.selectedIds = [...this.allProductIds];
                this.cart = this.allProducts.map(p => ({
                    product_id: p.product_id,
                    quantity: 1,
                    available: p.available
                }));
            }
        },

        onCheckChange(p) {
            const pid = p.product_id.toString();
            if (this.selectedIds.includes(pid)) {
                if (!this.cart.find(i => i.product_id === p.product_id)) {
                    this.cart.push({ product_id: p.product_id, quantity: 1 });
                }
            } else {
                this.cart = this.cart.filter(i => i.product_id !== p.product_id);
            }
        },

        getQty(p) {
            const item = this.cart.find(i => i.product_id === p.product_id);
            return item ? item.quantity : 1;
        },

        setQty(p, val) {
            let qty = parseInt(val);
            if (isNaN(qty) || qty < 1) qty = 1;
            if (qty > p.available) qty = p.available;
            const item = this.cart.find(i => i.product_id === p.product_id);
            if (item) item.quantity = qty;
        },

        adjustQty(p, delta) {
            const item = this.cart.find(i => i.product_id === p.product_id);
            if (!item) return;
            item.quantity = Math.max(1, Math.min(p.available, item.quantity + delta));
        },

        removeItem(p) {
            const pid = p.product_id.toString();
            this.selectedIds = this.selectedIds.filter(id => id !== pid);
            this.cart = this.cart.filter(i => i.product_id !== p.product_id);
        }
    };
}
</script>
@endpush
