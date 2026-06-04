@extends('management.layout')
@section('subtitle', 'New Transfer')

@push('styles')
<style>
    .prod-card { transition: all 0.1s ease; }
    .prod-card:hover { border-color: #93c5fd; }
    .prod-card.selected { border-color: #3b82f6; background: #eff6ff; }
</style>
@endpush

@section('content')
<div x-data="createTransfer()" x-init="init()">

    {{-- ═══════════ BREADCRUMB ═══════════ --}}
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-200">
        <div class="flex items-center gap-2 text-sm">
            <a href="{{ route('management.transfers.index') }}" class="text-slate-500 hover:text-blue-600 transition-colors">Transfers</a>
            <span class="text-slate-300">/</span>
            <span class="font-medium text-slate-700">New Transfer</span>
        </div>
    </div>

    {{-- Validation errors --}}
    @if($errors->any())
    <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 mb-4">
        <span class="w-1.5 h-1.5 rounded-full bg-red-500 mt-1.5 shrink-0"></span>
        <ul class="list-disc pl-4 space-y-0.5">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('management.transfers.store') }}" id="createTransferForm">
        @csrf
        {{-- Hidden fields for polymorphic locations --}}
        <input type="hidden" name="from_location_type" :value="fromType">
        <input type="hidden" name="from_location_id" :value="fromId">
        <input type="hidden" name="to_location_type" :value="toType">
        <input type="hidden" name="to_location_id" :value="toId">
        <input type="hidden" name="submitted" x-model="submitted">

        {{-- Hidden item fields rendered by Alpine x-for --}}
        <template x-for="(item, i) in cart" :key="i">
            <div>
                <input type="hidden" :name="'items['+i+'][product_id]'" :value="item.product_id">
                <input type="hidden" :name="'items['+i+'][quantity]'" :value="item.quantity">
            </div>
        </template>

        {{-- ═══════════ SOURCE + DESTINATION ═══════════ --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            {{-- Source --}}
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
                <div class="flex items-center gap-2 pl-3 py-2.5 border-b border-slate-200 bg-slate-50/50">
                    <span class="w-0.5 h-4 bg-orange-500 rounded-full"></span>
                    <h3 class="text-sm font-semibold text-slate-700">From</h3>
                </div>
                <div class="p-4 space-y-3">
                    <div class="flex rounded-md overflow-hidden border border-slate-200">
                        <label class="flex-1 cursor-pointer" @click="fromType = 'warehouse'; fromId = ''; cart = []; selectedIds = []">
                            <input type="radio" value="warehouse" x-model="fromType" class="hidden">
                            <span class="block text-center py-2 text-xs font-medium transition-all cursor-pointer select-none"
                                  :class="fromType === 'warehouse' ? 'bg-orange-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'">Warehouse</span>
                        </label>
                        <label class="flex-1 cursor-pointer" @click="fromType = 'store'; fromId = ''; cart = []; selectedIds = []">
                            <input type="radio" value="store" x-model="fromType" class="hidden">
                            <span class="block text-center py-2 text-xs font-medium transition-all cursor-pointer select-none"
                                  :class="fromType === 'store' ? 'bg-orange-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'">Store</span>
                        </label>
                    </div>
                    <select x-model="fromId" @change="onSourceChange()" class="w-full rounded-md border-slate-200 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 py-2">
                        <option value="">Select source</option>
                        <template x-if="fromType === 'warehouse'">
                            <optgroup label="Warehouses">
                                @foreach($warehouseList as $wh)
                                <option value="{{ $wh['id'] }}">{{ $wh['name'] }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                        <template x-if="fromType === 'store'">
                            <optgroup label="Stores">
                                @foreach($storeList as $store)
                                <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                    </select>
                </div>
            </div>

            {{-- Destination --}}
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
                <div class="flex items-center gap-2 pl-3 py-2.5 border-b border-slate-200 bg-slate-50/50">
                    <span class="w-0.5 h-4 bg-emerald-500 rounded-full"></span>
                    <h3 class="text-sm font-semibold text-slate-700">To</h3>
                </div>
                <div class="p-4 space-y-3">
                    <div class="flex rounded-md overflow-hidden border border-slate-200">
                        <label class="flex-1 cursor-pointer" @click="toType = 'warehouse'">
                            <input type="radio" value="warehouse" x-model="toType" class="hidden">
                            <span class="block text-center py-2 text-xs font-medium transition-all cursor-pointer select-none"
                                  :class="toType === 'warehouse' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'">Warehouse</span>
                        </label>
                        <label class="flex-1 cursor-pointer" @click="toType = 'store'">
                            <input type="radio" value="store" x-model="toType" class="hidden">
                            <span class="block text-center py-2 text-xs font-medium transition-all cursor-pointer select-none"
                                  :class="toType === 'store' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'">Store</span>
                        </label>
                    </div>
                    <select x-model="toId" class="w-full rounded-md border-slate-200 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 py-2">
                        <option value="">Select destination</option>
                        <template x-if="toType === 'warehouse'">
                            <optgroup label="Warehouses">
                                @foreach($warehouseList as $wh)
                                <option value="{{ $wh['id'] }}">{{ $wh['name'] }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                        <template x-if="toType === 'store'">
                            <optgroup label="Stores">
                                @foreach($storeList as $store)
                                <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        {{-- ═══════════ NOTES ═══════════ --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-4">
            <div class="p-4">
                <input type="text" name="notes" x-model="notes" placeholder="Optional note about this transfer..." class="w-full rounded-md border-slate-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 py-2">
            </div>
        </div>

        {{-- ═══════════ PRODUCT GRID ═══════════ --}}
        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-4">
            <div class="flex items-center justify-between pl-3 pr-4 py-2.5 border-b border-slate-200">
                <div class="flex items-center gap-2">
                    <span class="w-0.5 h-4 bg-blue-500 rounded-full"></span>
                    <h3 class="text-sm font-semibold text-slate-700">Products</h3>
                </div>
                <span class="text-xs text-slate-400" x-text="filteredProducts.length + ' available'"></span>
            </div>

            {{-- Search + Select All --}}
            <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-100 bg-slate-50/50 gap-3">
                <input type="text" placeholder="Search products..." x-model="searchQuery" @input.debounce.150ms="filter()" class="rounded-md border-slate-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 px-3 py-1.5 w-56">
                <div class="flex items-center gap-2" x-show="products.length > 0">
                    <span class="text-xs text-slate-500" x-text="selectedIds.length + ' selected'"></span>
                    <button type="button" @click="selectAllToggle()" class="text-xs text-blue-600 hover:text-blue-800 font-medium" x-text="selectedIds.length === allProductIds.length ? 'Deselect All' : 'Select All'"></button>
                </div>
            </div>

            {{-- Empty state when no source selected --}}
            <div x-show="!fromId" class="px-4 py-16 text-center">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-slate-100 text-slate-400 mb-3">
                    <i class="fi fi-rr-arrow-left-to-arc"></i>
                </span>
                <p class="text-sm font-medium text-slate-600 mb-1">Select a source location</p>
                <p class="text-xs text-slate-400">Choose a warehouse or store above to browse available products</p>
            </div>

            {{-- Empty state when no products --}}
            <div x-show="fromId && products.length === 0" class="px-4 py-16 text-center" x-cloak>
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-slate-100 text-slate-400 mb-3">
                    <i class="fi fi-rr-cube"></i>
                </span>
                <p class="text-sm font-medium text-slate-600 mb-1">No stock available</p>
                <p class="text-xs text-slate-400">This location has no products with available stock</p>
            </div>

            {{-- Product cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 p-4" id="productGrid" x-show="fromId && products.length > 0" x-cloak>
                <template x-for="p in filteredProducts" :key="p.product_id">
                    <div class="prod-card bg-white rounded-lg border border-slate-200 overflow-hidden"
                         :class="selectedIds.includes(p.product_id) ? 'selected' : ''"
                         x-show="visiblePids.includes(p.product_id)">
                        <div class="p-3">
                            <div class="flex items-start justify-between mb-2">
                                <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                                    <input type="checkbox" :value="p.product_id" x-model="selectedIds" @change="onCheckChange(p, $event)" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                </label>
                                <span class="text-[10px] text-slate-400 font-mono truncate ml-2" x-text="p.product_code"></span>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <div class="w-10 h-10 rounded-md bg-slate-100 flex items-center justify-center overflow-hidden shrink-0 border border-slate-200">
                                    <img :src="p.image ? '/storage/' + p.image : ''" :alt="p.name" class="w-full h-full object-cover" loading="lazy"
                                         x-show="p.image">
                                    <i class="fi fi-rr-cube text-slate-300 text-sm" x-show="!p.image"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-slate-700 truncate" x-text="p.name"></p>
                                    <div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-slate-50">
                                        <span class="text-xs font-medium text-slate-500" x-text="p.available + ' available'"></span>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium"
                                              :class="p.available > 10 ? 'bg-emerald-50 text-emerald-700' : (p.available > 0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700')"
                                              x-text="p.available + ' units'"></span>
                                    </div>
                                </div>
                            </div>
                            {{-- Quantity controls --}}
                            <div x-show="selectedIds.includes(p.product_id)" x-transition class="flex items-center gap-2 mt-2.5 pt-2 border-t border-slate-100">
                                <button type="button" @click="adjustQty(p.product_id, -1)" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">−</button>
                                <input type="number" :value="getQty(p.product_id)" min="1" :max="p.available" class="w-16 text-center rounded-md border-slate-200 text-sm py-1 focus:border-blue-500 focus:ring-blue-500" @input="setQty(p.product_id, $event.target.value, p.available)">
                                <button type="button" @click="adjustQty(p.product_id, 1)" class="w-7 h-7 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">+</button>
                                <button type="button" @click="removeItem(p.product_id)" class="ml-auto text-[11px] text-slate-400 hover:text-red-500 font-medium">Remove</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </form>

    {{-- ═══════════ FIXED BOTTOM BAR ═══════════ --}}
    <div x-show="cart.length > 0" x-transition class="fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-slate-200 shadow-lg px-6 py-3" x-cloak>
        <div class="max-w-7xl mx-auto flex items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-slate-800" x-text="cart.length + ' items'"></span>
                <span class="text-xs text-slate-300">·</span>
                <span class="text-sm font-semibold text-slate-800" x-text="cart.reduce((s, i) => s + i.quantity, 0) + ' units'"></span>
            </div>
            <div class="flex-1"></div>
            <button type="button" @click="cart = []; selectedIds = []" class="text-xs text-slate-400 hover:text-red-500 font-medium">Clear all</button>
            <button type="submit" form="createTransferForm" @click="submitted = '0'" :disabled="cart.length === 0 || !fromId || !toId" class="px-4 py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-md hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                Save Draft
            </button>
            <button type="submit" form="createTransferForm" @click="submitted = '1'" :disabled="cart.length === 0 || !fromId || !toId" class="px-5 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                Submit for Approval
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function createTransfer() {
    const stockData = @json($allStockData);

    return {
        fromType: 'warehouse',
        fromId: @json($preSelectFromWarehouseId ? (string) $preSelectFromWarehouseId : ''),
        toType: @json($preSelectToWarehouseId ? 'warehouse' : 'store'),
        toId: @json($preSelectToWarehouseId ? (string) $preSelectToWarehouseId : ''),
        notes: '',
        submitted: '0',

        searchQuery: '',
        selectedIds: [],
        cart: [],
        products: [],
        allProductIds: [],
        visiblePids: [],

        init() {
            this.onSourceChange();
        },

        onSourceChange() {
            this.cart = [];
            this.selectedIds = [];
            this.searchQuery = '';
            const key = this.fromType + '_' + this.fromId;
            this.products = stockData[key] || [];
            this.allProductIds = this.products.map(p => p.product_id);
            this.visiblePids = [...this.allProductIds];
        },

        filter() {
            const q = this.searchQuery.toLowerCase();
            if (!q) {
                this.visiblePids = [...this.allProductIds];
                return;
            }
            this.visiblePids = this.products
                .filter(p => p.name.toLowerCase().includes(q) || p.product_code.toLowerCase().includes(q))
                .map(p => p.product_id);
        },

        selectAllToggle() {
            if (this.selectedIds.length === this.allProductIds.length) {
                this.selectedIds = [];
                this.cart = [];
            } else {
                this.selectedIds = [...this.allProductIds];
                this.cart = this.allProductIds.map(id => ({
                    product_id: id,
                    quantity: 1
                }));
            }
        },

        onCheckChange(p, event) {
            const pid = p.product_id;
            if (event.target.checked) {
                if (!this.cart.find(i => i.product_id === pid)) {
                    this.cart.push({ product_id: pid, quantity: 1 });
                }
            } else {
                this.cart = this.cart.filter(i => i.product_id !== pid);
            }
        },

        getQty(pid) {
            const item = this.cart.find(i => i.product_id === pid);
            return item ? item.quantity : 1;
        },

        setQty(pid, val, max) {
            let qty = parseInt(val);
            if (isNaN(qty) || qty < 1) qty = 1;
            if (qty > max) qty = max;
            const item = this.cart.find(i => i.product_id === pid);
            if (item) item.quantity = qty;
        },

        adjustQty(pid, delta) {
            const item = this.cart.find(i => i.product_id === pid);
            if (!item) return;
            const prod = this.products.find(p => p.product_id === pid);
            const max = prod ? prod.available : 1;
            item.quantity = Math.max(1, Math.min(max, item.quantity + delta));
        },

        removeItem(pid) {
            this.selectedIds = this.selectedIds.filter(id => id !== pid);
            this.cart = this.cart.filter(i => i.product_id !== pid);
        },

        get filteredProducts() {
            return this.products.filter(p => this.visiblePids.includes(p.product_id));
        }
    };
}
</script>
@endpush
