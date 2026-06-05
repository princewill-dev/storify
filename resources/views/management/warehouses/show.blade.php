@extends('management.layout')
@section('subtitle', $warehouse->name)

@push('styles')
<style>
    .tab-btn.active { border-bottom: 2px solid #1e293b; color: #1e293b; }
    .item-card { transition: all 0.1s; }
    .item-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .item-card.selected { border-color: #6366f1; background: #eef2ff; }
</style>
@endpush

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$warehouse->name" subtitle="Warehouse · {{ $warehouse->warehouse_code }}">
    <x-slot:actions>
        <x-management.status-badge :status="$warehouse->isActive() ? 'active' : 'inactive'" />
        @can('transfers create')
        <a href="{{ route('management.transfers.create', ['from_warehouse' => $warehouse->warehouse_code]) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fi fi-rr-arrows-exchange text-xs"></i> Stock Adjustment
        </a>
        @endcan
    </x-slot:actions>
</x-management.page-header>

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 mb-4">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total Stock</p>
        <p class="text-xl font-bold text-slate-800 mt-1">{{ number_format($totalStock) }} <span class="text-xs font-normal text-slate-400">{{ $productCount }} products</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Sections</p>
        <p class="text-xl font-bold text-slate-800 mt-1">{{ $warehouse->sections->where('status', '!=', \App\Enums\SectionStatus::DELETED->value)->count() }} <span class="text-xs font-normal text-slate-400">{{ $warehouse->sections->where('status', \App\Enums\SectionStatus::ACTIVE->value)->count() }} active</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border {{ $lowStockCount > 0 ? 'border-amber-200' : 'border-slate-200' }} p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Low Stock</p>
        <p class="text-xl font-bold {{ $lowStockCount > 0 ? 'text-amber-600' : 'text-slate-800' }} mt-1">{{ $lowStockCount }} <span class="text-xs font-normal text-slate-400">{{ $lowStockCount > 0 ? 'need restock' : 'all stocked' }}</span></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Assigned Staff</p>
        <p class="text-xl font-bold text-slate-800 mt-1">{{ $warehouse->assignedStaff->count() }} <span class="text-xs font-normal text-slate-400">members</span></p>
    </div>
</div>

<div x-data="warehouseTabs('{{ $products->pluck('id')->join(',') }}', '{{ $warehouse->warehouse_code }}')">
    <div class="flex items-center gap-1 mb-4 border-b border-slate-200">
        <button @click="tab = 'products'" :class="tab === 'products' ? 'active' : ''" class="tab-btn px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors border-b-2 border-transparent">Products · {{ $productCount }}</button>
        <button @click="tab = 'sections'" :class="tab === 'sections' ? 'active' : ''" class="tab-btn px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors border-b-2 border-transparent">Sections · {{ $warehouse->sections->count() }}</button>
        <button @click="tab = 'activity'" :class="tab === 'activity' ? 'active' : ''" class="tab-btn px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors border-b-2 border-transparent">Activity</button>
        <button @click="switchSettingsTab()" :class="tab === 'settings' ? 'active' : ''" class="tab-btn px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700 transition-colors border-b-2 border-transparent">Settings</button>
    </div>

    {{-- Products Tab --}}
    <div x-show="tab === 'products'">
        @if($products->isNotEmpty())
        {{-- Bulk Action Bar --}}
        <div x-show="selectedIds.length > 0" x-transition class="sticky top-0 z-30 mb-4 bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-2.5 flex items-center gap-3">
            <span class="text-sm font-medium text-slate-700" x-text="selectedIds.length + ' selected'"></span>
            <button @click="if (selectAll) { selectAll = false; selectedIds = []; } else { selectAll = true; selectedIds = [...allIds]; }" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                <span x-show="!selectAll">Select All ({{ $products->count() }})</span>
                <span x-show="selectAll">Deselect All</span>
            </button>
            <div class="flex-1"></div>
            @can('products delete')
            <button @click="confirmingBulkDelete = true" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700 transition-colors">
                <i class="fi fi-rr-trash text-xs"></i> Delete
            </button>
            @endcan
        </div>

        {{-- Bulk Delete Confirmation Modal --}}
        <div x-show="confirmingBulkDelete" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-900/50" @click="confirmingBulkDelete = false"></div>
                <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6" @click.outside="confirmingBulkDelete = false">
                    <div class="text-center mb-4">
                        <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-red-100 text-red-500 mb-3"><i class="fi fi-rr-trash text-lg"></i></span>
                        <h3 class="text-base font-semibold text-slate-800">Delete Products?</h3>
                        <p class="text-sm text-slate-500 mt-1">This will permanently remove <strong x-text="selectedIds.length"></strong> product(s). This action cannot be undone.</p>
                    </div>
                    <form method="POST" action="{{ route('management.products.bulk-destroy') }}">
                        @csrf
                        <template x-for="id in selectedIds" :key="id"><input type="hidden" name="product_ids[]" :value="id"></template>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="flex-1 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors">Delete</button>
                            <button type="button" @click="confirmingBulkDelete = false" class="flex-1 py-2.5 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="flex items-center mb-4">
            <input type="text" placeholder="Search products..." class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 px-3.5 py-2 w-64" oninput="document.querySelectorAll('.item-card').forEach(c=>c.style.display=c.dataset.name.toLowerCase().includes(this.value.toLowerCase())?'':'none')">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @foreach($products as $product)
            <div class="item-card bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm" data-name="{{ $product->name }}" :class="selectedIds.includes('{{ $product->id }}') ? 'selected' : ''">
                <div class="p-3">
                    {{-- Top row: checkbox + 3-dot menu --}}
                    <div class="flex items-start justify-between mb-2">
                        <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                            <input type="checkbox" value="{{ $product->id }}" x-model="selectedIds" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4">
                        </label>
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <button @click.stop="open = !open" class="p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                            </button>
                            <div x-show="open" x-transition class="absolute right-0 mt-1 w-36 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-40">
                                <a href="{{ route('management.products.show', $product) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"><i class="fi fi-rr-eye w-4 text-slate-400"></i> View</a>
                                <a href="{{ route('management.products.edit', $product) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"><i class="fi fi-rr-edit w-4 text-slate-400"></i> Edit</a>
                                <form method="POST" action="{{ route('management.products.destroy', $product) }}" onsubmit="return confirm('Delete this product?')" class="block">
                                    @csrf @method('DELETE')
                                    <button class="flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left"><i class="fi fi-rr-trash w-4"></i> Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- Image + Product info --}}
                    <div class="flex items-start gap-3">
                    @php $img = $product->images->first(); @endphp
                    <a href="{{ route('management.products.show', $product) }}" class="w-14 h-14 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden shrink-0">
                        @if($img && $img->path)
                        <img src="{{ asset('storage/' . $img->path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover" loading="lazy">
                        @else
                        <i class="fi fi-rr-cube text-slate-300 text-lg"></i>
                        @endif
                    </a>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('management.products.show', $product) }}" class="text-sm font-semibold text-slate-800 hover:text-indigo-600 transition-colors truncate block">{{ $product->name }}</a>
                        @if($product->section)
                        <p class="text-[11px] text-slate-400 mt-0.5">{{ $product->section->name }}</p>
                        @endif
                        <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-50">
                            <span class="text-sm font-bold text-slate-700">₦{{ number_format($product->amount, 0) }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $product->quantity > 10 ? 'bg-emerald-50 text-emerald-700' : ($product->quantity > 0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700') }}">{{ $product->quantity }} units</span>
                        </div>
                        @if($product->store_id)
                        <p class="text-[10px] text-emerald-600 mt-1 flex items-center gap-1"><i class="fi fi-rr-shop"></i> At Store: {{ $product->store?->name ?? 'Store #'.$product->store_id }}</p>
                        @endif
                    </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-cube text-lg"></i></span>
            <h3 class="text-sm font-semibold text-slate-700 mb-1">No products in this warehouse</h3>
            <p class="text-xs text-slate-400 mb-4">Products assigned to this warehouse will appear here</p>
            <a href="{{ route('management.products.create', ['warehouse_id' => $warehouse->id]) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Add Product</a>
        </div>
        @endif
    </div>

    {{-- Sections Tab --}}
    <div x-show="tab === 'sections'">
        @php $visibleSections = $warehouse->sections->where('status', '!=', \App\Enums\SectionStatus::DELETED->value); @endphp
        @if($visibleSections->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($visibleSections as $section)
            <a href="{{ route('management.sections.show', [$warehouse, $section]) }}" class="bg-white rounded-xl border border-slate-200 p-4 hover:shadow hover:border-slate-300 transition-all">
                <div class="flex items-start justify-between mb-2">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl {{ $section->isActive() ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400' }}"><i class="fi fi-rr-cube text-sm"></i></span>
                    <x-management.status-badge :status="$section->isActive() ? 'active' : 'inactive'" />
                </div>
                <h3 class="text-sm font-semibold text-slate-800">{{ $section->name }}</h3>
                <p class="text-xs text-slate-400 mt-0.5">{{ $section->products_count ?? $section->products->count() }} products · {{ $section->section_code }}</p>
            </a>
            @endforeach
        </div>
        <div class="mt-3 text-right">
            <a href="{{ route('management.sections.create', $warehouse) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800"><i class="fi fi-rr-plus text-xs"></i> Add Section</a>
        </div>
        @else
        <div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-cube text-lg"></i></span>
            <h3 class="text-sm font-semibold text-slate-700 mb-1">No sections yet</h3>
            <p class="text-xs text-slate-400 mb-4">Organize your warehouse into physical zones</p>
            <a href="{{ route('management.sections.create', $warehouse) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800">Create Section</a>
        </div>
        @endif
    </div>

    {{-- Activity Tab --}}
    <div x-show="tab === 'activity'">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            @if($recentMovements->isNotEmpty())
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-100">
                    <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Product</th>
                    <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase hidden sm:table-cell">Type</th>
                    <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase">Qty</th>
                    <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase hidden md:table-cell">Before</th>
                    <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase hidden md:table-cell">After</th>
                    <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase hidden lg:table-cell">By</th>
                    <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase hidden md:table-cell">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($recentMovements as $mov)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-5 py-3 text-sm font-medium text-slate-800 truncate max-w-[180px]">{{ $mov->product?->name ?? '—' }}</td>
                        <td class="px-5 py-3 text-center hidden sm:table-cell">
                            @if($mov->type === 'added') <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">IN</span>
                            @elseif($mov->type === 'removed') <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-medium text-red-700">OUT</span>
                            @else <span class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700">MOVE</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-sm font-semibold {{ $mov->type === 'removed' ? 'text-red-600' : 'text-emerald-600' }}">{{ $mov->type === 'removed' ? '−' : '+' }}{{ $mov->quantity }}</td>
                        <td class="px-5 py-3 text-right text-sm text-slate-500 hidden md:table-cell">{{ $mov->balance_before ?? '—' }}</td>
                        <td class="px-5 py-3 text-right text-sm font-medium text-slate-700 hidden md:table-cell">{{ $mov->balance_after ?? '—' }}</td>
                        <td class="px-5 py-3 hidden lg:table-cell text-xs text-slate-400">{{ $mov->performedBy?->name ?? 'System' }}</td>
                        <td class="px-5 py-3 text-right text-xs text-slate-400 hidden md:table-cell">{{ $mov->created_at->format('M d, h:i A') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="px-5 py-12 text-center text-sm text-slate-400">No stock movements recorded yet for this warehouse.</div>
            @endif
        </div>
    </div>

    {{-- Settings Tab (AJAX-loaded) --}}
    <div x-show="tab === 'settings'" x-cloak>
        {{-- Loading Spinner --}}
        <div x-show="tabLoading" class="flex items-center justify-center py-20">
            <div class="text-center space-y-3">
                <div class="inline-block w-8 h-8 border-2 border-slate-200 border-t-slate-600 rounded-full animate-spin"></div>
                <p class="text-sm text-slate-400">Loading settings...</p>
            </div>
        </div>

        {{-- Error State --}}
        <div x-show="tabError" x-cloak class="text-center py-20">
            <i class="fi fi-rr-exclamation text-4xl text-red-300 mb-3 block"></i>
            <p class="text-sm text-red-500" x-text="tabError"></p>
            <button @click="fetchSettingsTab()" class="mt-3 px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50">Retry</button>
        </div>

        {{-- Settings Content --}}
        <div x-show="!tabLoading && !tabError" x-html="tabContent['settings'] || ''"></div>
    </div>

    {{-- Warehouse Info below tabs --}}
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-3">Warehouse Info</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-sm">
            <div><span class="text-xs text-slate-400 block">Code</span><span class="font-mono font-medium text-slate-700">{{ $warehouse->warehouse_code }}</span></div>
            @if($warehouse->address)<div><span class="text-xs text-slate-400 block">Address</span><span class="font-medium text-slate-700">{{ $warehouse->address }}</span></div>@endif
            <div><span class="text-xs text-slate-400 block">Location</span><span class="font-medium text-slate-700">{{ collect([$warehouse->city, $warehouse->state])->filter()->join(', ') ?: '—' }}</span></div>
            @if($warehouse->contact_person)<div><span class="text-xs text-slate-400 block">Contact</span><span class="font-medium text-slate-700">{{ $warehouse->contact_person }}</span></div>@endif
            @if($warehouse->contact_phone)<div><span class="text-xs text-slate-400 block">Phone</span><span class="font-medium text-slate-700">{{ $warehouse->contact_phone }}</span></div>@endif
            <div><span class="text-xs text-slate-400 block">Created</span><span class="font-medium text-slate-700">{{ $warehouse->created_at->format('d M Y') }}</span></div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('warehouseTabs', (allIdsStr, warehouseCode) => ({
        tab: 'products',
        selectedIds: [],
        selectAll: false,
        confirmingBulkDelete: false,
        allIds: allIdsStr ? allIdsStr.split(',') : [],
        tabLoading: false,
        tabError: null,
        tabContent: {},

        switchSettingsTab() {
            this.tab = 'settings';
            this.tabError = null;
            if (!this.tabContent['settings']) {
                this.fetchSettingsTab();
            }
        },

        async fetchSettingsTab() {
            this.tabLoading = true;
            this.tabError = null;
            try {
                const resp = await fetch(`/management/warehouses/${warehouseCode}/tab/settings`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
                });
                if (!resp.ok) throw new Error(`Server error (${resp.status})`);
                this.tabContent['settings'] = await resp.text();
            } catch (e) {
                console.error('Settings tab fetch error:', e);
                this.tabError = e.message || 'Failed to load settings.';
            } finally {
                this.tabLoading = false;
            }
        }
    }));
});
</script>
@endpush
