@extends('management.layout')
@section('subtitle', 'Send Inventory · ' . $warehouse->name)

@push('styles')
<style>
    .send-card { transition: all 0.1s; }
    .send-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .send-card.selected { border-color: #6366f1; background: #eef2ff; }
</style>
@endpush

@section('content')
<div x-data="sendTransfer()" x-init="init()">
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ route('management.warehouses.show', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <i class="fi fi-rr-arrow-left text-xs"></i> Back
        </a>
        <div class="h-4 w-px bg-slate-200"></div>
        <span class="text-sm font-semibold text-slate-800">Send from {{ $warehouse->name }}</span>
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

    <form method="POST" action="{{ route('management.warehouses.send.store', $warehouse) }}" id="sendFormMain">
        @csrf
        <template x-for="(item, i) in cart" :key="i">
            <input type="hidden" :name="'items['+i+'][product_id]'" :value="item.product_id">
            <input type="hidden" :name="'items['+i+'][quantity]'" :value="item.quantity">
        </template>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4">
            <div class="flex items-center gap-3 mb-3">
                <span class="text-xs font-medium text-slate-500 uppercase tracking-wider w-20 shrink-0">To</span>
                <div class="flex">
                    <label class="cursor-pointer" @click="destType = 'warehouse'">
                        <input type="radio" name="to_location_type" value="warehouse" x-model="destType" class="hidden peer">
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-l-lg border transition-colors peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 border-slate-200 text-slate-500 hover:border-slate-300"><i class="fi fi-rr-warehouse-alt text-[11px]"></i> Warehouse</span>
                    </label>
                    <label class="cursor-pointer" @click="destType = 'store'">
                        <input type="radio" name="to_location_type" value="store" x-model="destType" class="hidden peer">
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-r-lg border border-l-0 transition-colors peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 border-slate-200 text-slate-500 hover:border-slate-300"><i class="fi fi-rr-shop text-[11px]"></i> Store</span>
                    </label>
                </div>
            </div>
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <select name="to_location_id" x-model="destId" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2" required>
                        <option value="">Select destination</option>
                        <template x-if="destType === 'warehouse'">
                            <optgroup label="Warehouses">
                                @foreach($warehouses as $wh)
                                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                        <template x-if="destType === 'store'">
                            <optgroup label="Stores">
                                @foreach($stores as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </optgroup>
                        </template>
                    </select>
                </div>
                <div class="flex-1">
                    <input type="text" name="notes" x-model="notes" placeholder="Optional note..." class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2">
                </div>
                <button type="submit" :disabled="cart.length === 0 || !destId" class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors shrink-0">
                    <i class="fi fi-rr-paper-plane text-xs"></i> Send
                </button>
            </div>
        </div>

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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3" id="productGrid">
            @foreach($stockLocations as $loc)
            @php
                if ($loc instanceof \App\Models\StockLocation) {
                    $pid = $loc->product_id;
                    $pqty = (int) $loc->quantity;
                    $p = $loc->product;
                } else {
                    $pid = $loc->id;
                    $pqty = (int) $loc->quantity;
                    $p = $loc;
                }
                $img = $p?->images?->first();
            @endphp
            @if($p)
            <div class="send-card bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm"
                 data-pid="{{ $pid }}"
                 data-name="{{ $p->name }}"
                 data-available="{{ $pqty }}"
                 :class="selectedIds.includes('{{ $pid }}') ? 'selected' : ''"
                 x-show="visiblePids.includes('{{ $pid }}')">
                <div class="p-3">
                    <div class="flex items-start justify-between mb-2">
                        <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                            <input type="checkbox" value="{{ $pid }}" x-model="selectedIds" @change="onCheckChange('{{ $pid }}')" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4">
                        </label>
                        <span class="text-[10px] text-slate-400 font-mono">{{ $p->product_code }}</span>
                    </div>

                    <div class="flex items-start gap-3">
                        <a href="{{ route('management.products.show', $p) }}" class="w-14 h-14 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
                            @if($img && $img->path)
                            <img src="{{ asset('storage/' . $img->path) }}" alt="{{ $p->name }}" class="w-full h-full object-cover" loading="lazy">
                            @else
                            <i class="fi fi-rr-cube text-slate-300 text-lg"></i>
                            @endif
                        </a>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('management.products.show', $p) }}" class="text-sm font-semibold text-slate-800 hover:text-indigo-600 transition-colors truncate block">{{ $p->name }}</a>
                            @if($p->section)
                            <p class="text-[11px] text-slate-400 mt-0.5">{{ $p->section->name }}</p>
                            @endif
                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-50">
                                <span class="text-xs font-medium text-slate-500">{{ $pqty }} available</span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $pqty > 10 ? 'bg-emerald-50 text-emerald-700' : ($pqty > 0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700') }}">{{ $pqty }} units</span>
                            </div>
                        </div>
                    </div>

                    <div x-show="selectedIds.includes('{{ $pid }}')" x-transition class="flex items-center gap-2 mt-3 pt-2 border-t border-slate-100">
                        <button type="button" @click="adjustQty('{{ $pid }}', -1)" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">−</button>
                        <input type="number" :value="getQty('{{ $pid }}')" min="1" max="{{ $pqty }}" class="w-20 text-center rounded-lg border-slate-200 text-sm py-1.5 focus:border-indigo-500 focus:ring-indigo-500" @input="setQty('{{ $pid }}', $event.target.value, {{ $pqty }})">
                        <button type="button" @click="adjustQty('{{ $pid }}', 1)" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm flex items-center justify-center font-semibold transition-colors">+</button>
                        <button type="button" @click="removeItem('{{ $pid }}')" class="ml-auto text-[11px] text-slate-400 hover:text-red-500 font-medium">Remove</button>
                    </div>
                </div>
            </div>
            @endif
            @endforeach
        </div>
    </form>

    <div x-show="cart.length > 0" x-transition class="fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-slate-200 shadow-lg px-6 py-3">
        <div class="max-w-7xl mx-auto flex items-center gap-4">
            <div class="flex items-center gap-3">
                <span class="text-sm font-semibold text-slate-800" x-text="cart.length + ' items'"></span>
                <span class="text-xs text-slate-400">·</span>
                <span class="text-sm font-semibold text-slate-800" x-text="cart.reduce((s, i) => s + i.quantity, 0) + ' units'"></span>
            </div>
            <div class="flex-1"></div>
            <button type="button" @click="cart = []; selectedIds = []" class="text-xs text-slate-400 hover:text-red-500 font-medium">Clear all</button>
            <button type="button" onclick="document.getElementById('sendFormMain').submit()" :disabled="cart.length === 0 || !destId" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <i class="fi fi-rr-paper-plane text-xs"></i> Send
            </button>
        </div>
    </div>
</div>

@if($stockLocations->isEmpty())
<div class="bg-white rounded-xl border border-slate-200 p-12 text-center mt-4">
    <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-cube text-lg"></i></span>
    <h3 class="text-sm font-semibold text-slate-700 mb-1">No stock available</h3>
    <p class="text-xs text-slate-400">This warehouse has no products with available stock.</p>
</div>
@endif
@endsection

@push('scripts')
<script>
function sendTransfer() {
    const allProducts = [
        @foreach($stockLocations as $loc)
        @php
            if ($loc instanceof \App\Models\StockLocation) {
                $pid = $loc->product_id;
                $pqty = (int) $loc->quantity;
            } else {
                $pid = $loc->id;
                $pqty = (int) $loc->quantity;
            }
        @endphp
        { product_id: '{{ $pid }}', available: {{ $pqty }} },
        @endforeach
    ];

    return {
        destType: 'warehouse',
        destId: '',
        notes: '',
        searchQuery: '',
        selectedIds: [],
        cart: [],
        allProductIds: allProducts.map(p => p.product_id),
        productsMap: Object.fromEntries(allProducts.map(p => [p.product_id, p])),
        visiblePids: allProducts.map(p => p.product_id),

        init() {
            this.visiblePids = [...this.allProductIds];
        },

        filter() {
            const q = this.searchQuery.toLowerCase();
            if (!q) {
                this.visiblePids = [...this.allProductIds];
                return;
            }
            this.visiblePids = [];
            document.querySelectorAll('#productGrid .send-card').forEach(card => {
                const name = (card.dataset.name || '').toLowerCase();
                if (name.includes(q)) {
                    this.visiblePids.push(card.dataset.pid);
                }
            });
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

        onCheckChange(pid) {
            if (this.selectedIds.includes(pid)) {
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
            const max = (this.productsMap[pid] || {}).available || 1;
            item.quantity = Math.max(1, Math.min(max, item.quantity + delta));
        },

        removeItem(pid) {
            this.selectedIds = this.selectedIds.filter(id => id !== pid);
            this.cart = this.cart.filter(i => i.product_id !== pid);
        },

        get filteredProducts() {
            return this.allProductIds.filter(id => this.visiblePids.includes(id));
        }
    };
}
</script>
@endpush
