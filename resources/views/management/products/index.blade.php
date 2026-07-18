@extends('management.layout')
@section('subtitle', 'Products')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Products" subtitle="Manage your product catalog">
    <x-slot:actions>
        <a href="{{ route('management.products.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Product
        </a>
    </x-slot:actions>
</x-management.page-header>

<x-management.data-table>
    <x-slot:search>
        <form method="GET" id="productFilters" class="flex flex-wrap items-center gap-2 flex-1">
            <input type="text" name="q" id="productSearch" value="{{ $q ?? '' }}" placeholder="Search by name, code, or category..." autocomplete="off"
                class="flex-1 min-w-[200px] rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)">
            <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Status</option>
                <option value="active" @selected(($status ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($status ?? '') === 'inactive')>Inactive</option>
            </select>
            <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                <option value="">All Stores</option>
                @foreach($stores as $store)
                <option value="{{ $store->store_id }}" @selected(request('store_id') === $store->store_id)>{{ $store->name }}</option>
                @endforeach
            </select>
            @if($q || $status || request('store_id'))
            <a href="{{ route('management.products.index') }}" class="px-3 py-2 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
            @endif
        </form>
    </x-slot:search>
    <x-slot:header>
        <th class="px-3 py-3 w-10"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer"></th>
        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Product</th>
        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Store</th>
        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden lg:table-cell">Section</th>
        <th class="px-3 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden lg:table-cell">Source</th>
        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Price</th>
        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Stock</th>
        <th class="px-3 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider hidden xl:table-cell">Code</th>
        <th class="px-3 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>
    @forelse($products ?? [] as $product)
    <tr class="hover:bg-slate-50 transition-colors product-row">
        <td class="px-3 py-3"><input type="checkbox" class="product-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer" value="{{ $product->id }}" onchange="updateSelection()"></td>
        <td class="px-3 py-3">
            <a href="{{ route('management.products.show', $product) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $product->name }}</a>
        </td>
        <td class="px-3 py-3 hidden md:table-cell"><span class="text-sm text-slate-500">{{ $product->store?->name ?? 'N/A' }}</span></td>
        <td class="px-3 py-3 hidden lg:table-cell"><span class="text-sm text-slate-500">{{ $product->section?->name ?? '—' }}</span></td>
        <td class="px-3 py-3 hidden lg:table-cell"><span class="text-sm text-slate-500">{{ $product->section?->warehouse?->name ?? '—' }}</span></td>
        <td class="px-3 py-3 text-right"><span class="text-sm font-semibold text-slate-800">{{ $displayPrices[$product->id] ?? '₦' . number_format($product->amount, 2) }}</span></td>
        <td class="px-3 py-3 text-center hidden sm:table-cell">
            <span class="text-sm {{ $product->quantity <= 10 ? 'text-amber-600 font-semibold' : 'text-slate-600' }}">{{ $product->quantity }}</span>
        </td>
        <td class="px-3 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$product->status" /></td>
        <td class="px-3 py-3 text-right hidden xl:table-cell"><span class="text-xs text-slate-400 font-mono">{{ $product->product_code }}</span></td>
        <td class="px-3 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.products.show', $product) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View"><i class="fi fi-rr-eye text-xs"></i></a>
                <a href="{{ route('management.products.edit', $product) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit"><i class="fi fi-rr-edit text-xs"></i></a>
                <button onclick="openModal('deleteProduct{{ $product->id }}')" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete"><i class="fi fi-rr-trash text-xs"></i></button>
                <x-management.confirm-modal id="deleteProduct{{ $product->id }}" title="Delete Product" message="Delete this product?" action="{{ route('management.products.destroy', $product) }}" method="DELETE" />
            </div>
        </td>
    </tr>
    @empty
    <tr><td colspan="9" class="px-5 py-12">
        <x-management.empty-state icon="fi fi-rr-cube" title="No products yet" description="Start building your catalog by adding your first product." action-label="Add Product" action-url="{{ route('management.products.create') }}" />
    </td></tr>
    @endforelse
</x-management.data-table>
@endsection

@push('modals')
{{-- Bulk Edit Modal --}}
<div id="bulkEditModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('bulkEditModal')"></div>
        <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-xl max-h-[85vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0">
                <h3 class="text-base font-semibold text-slate-800">Bulk Edit <span id="bulkEditCount" class="text-slate-400 font-normal"></span></h3>
                <button onclick="closeModal('bulkEditModal')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg">&times;</button>
            </div>
            <form id="bulkEditForm" method="POST" action="{{ route('management.products.bulk-update') }}" class="flex-1 overflow-y-auto">
                @csrf
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-100 sticky top-0 bg-white z-10">
                            <tr>
                                <th class="px-4 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Product</th>
                                <th class="px-4 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase w-32">Price (₦)</th>
                                <th class="px-4 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase w-32">Stock</th>
                                <th class="px-4 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase w-32">Status</th>
                            </tr>
                        </thead>
                        <tbody id="bulkEditTableBody" class="divide-y divide-slate-50">
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-slate-100 bg-white flex items-center gap-3 shrink-0 sticky bottom-0">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Apply Changes</button>
                    <button type="button" onclick="closeModal('bulkEditModal')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Bulk Delete Confirmation Modal --}}
<div id="bulkDeleteModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('bulkDeleteModal')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Delete Selected Products</h3></div>
            <div class="p-6 space-y-4">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-red-100 mb-3">
                        <i class="fi fi-rr-trash text-xl text-red-500"></i>
                    </div>
                    <p class="text-sm text-slate-600">Delete <span id="bulkDeleteCount"></span> selected products? This cannot be undone.</p>
                </div>
                <form method="POST" action="{{ route('management.products.bulk-destroy') }}">
                    @csrf
                    <input type="hidden" name="product_ids" id="bulkDeleteIds">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-lg">Delete All</button>
                        <button type="button" onclick="closeModal('bulkDeleteModal')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
const selectAll = document.getElementById('selectAll');
const actionBar = document.createElement('div');
actionBar.id = 'bulkActionBar';
actionBar.className = 'hidden fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-slate-200 shadow-lg px-6 py-3 flex items-center gap-3 justify-center';
actionBar.innerHTML = `
    <span id="bulkCount" class="text-sm font-medium text-slate-700 mr-2">0 selected</span>
    <button onclick="openBulkEdit()" class="px-3 py-1.5 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800">Bulk Edit</button>
    <form method="POST" action="{{ route('management.products.bulk-status') }}" id="bulkActivateForm" class="inline">@csrf<input type="hidden" name="status" value="active"><input type="hidden" name="product_ids" id="bulkActivateIds"><button class="px-3 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700">Mark Active</button></form>
    <form method="POST" action="{{ route('management.products.bulk-status') }}" id="bulkDeactivateForm" class="inline">@csrf<input type="hidden" name="status" value="inactive"><input type="hidden" name="product_ids" id="bulkDeactivateIds"><button class="px-3 py-1.5 bg-amber-600 text-white text-xs font-semibold rounded-lg hover:bg-amber-700">Mark Inactive</button></form>
    <button onclick="openBulkDelete()" class="px-3 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700">Delete Selected</button>
`;
document.body.appendChild(actionBar);

let searchTimer;
document.getElementById('productSearch')?.addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => this.form.submit(), 300);
});

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
}

function updateSelection() {
    const ids = getSelectedIds();
    const count = ids.length;
    document.getElementById('bulkCount').textContent = count + ' selected';
    actionBar.classList.toggle('hidden', count === 0);
    const activateIds = document.getElementById('bulkActivateIds');
    const deactivateIds = document.getElementById('bulkDeactivateIds');
    if (count > 0) {
        const joined = ids.join(',');
        activateIds.value = joined;
        deactivateIds.value = joined;
    }
    document.getElementById('selectAll').checked = count > 0 && document.querySelectorAll('.product-checkbox').length === count;
}

function toggleSelectAll(el) {
    document.querySelectorAll('.product-checkbox').forEach(cb => { cb.checked = el.checked; });
    updateSelection();
}

function openBulkEdit() {
    const checkboxes = document.querySelectorAll('.product-checkbox:checked');
    if (!checkboxes.length) return;

    const rows = [];
    checkboxes.forEach(cb => {
        const tr = cb.closest('tr');
        const name = tr.querySelector('a.text-blue-600')?.textContent.trim() || '';
        const priceEl = tr.querySelector('td:nth-last-child(5) span');
        const priceText = priceEl?.textContent.trim() || '0';
        const price = parseFloat(priceText.replace(/[₦,]/g, '')) || 0;
        const stockEl = tr.querySelector('td:nth-last-child(4) span');
        const stock = parseInt(stockEl?.textContent.trim()) || 0;
        const statusEl = tr.querySelector('td:nth-last-child(3) span');
        const statusText = statusEl?.textContent.trim().toLowerCase() || '';
        const status = statusText.includes('active') ? 'active' : 'inactive';
        rows.push({ id: cb.value, name, price, stock, status });
    });

    const tbody = document.getElementById('bulkEditTableBody');
    tbody.innerHTML = rows.map((p, i) => `
        <tr class="hover:bg-slate-50/50">
            <td class="px-4 py-2.5">
                <input type="hidden" name="products[${i}][id]" value="${p.id}">
                <span class="text-sm font-medium text-slate-800">${p.name}</span>
            </td>
            <td class="px-4 py-2.5">
                <input type="number" name="products[${i}][amount]" step="0.01" min="0" value="${p.price}" class="w-full rounded-lg border-slate-300 px-2.5 py-1.5 text-xs text-center shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </td>
            <td class="px-4 py-2.5">
                <input type="number" name="products[${i}][quantity]" min="0" value="${p.stock}" class="w-full rounded-lg border-slate-300 px-2.5 py-1.5 text-xs text-center shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            </td>
            <td class="px-4 py-2.5">
                <select name="products[${i}][status]" class="w-full rounded-lg border-slate-300 px-2 py-1.5 text-xs text-center shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="active" ${p.status === 'active' ? 'selected' : ''}>Active</option>
                    <option value="inactive" ${p.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                </select>
            </td>
        </tr>
    `).join('');

    document.getElementById('bulkEditCount').textContent = '(' + rows.length + ' products)';
    openModal('bulkEditModal');
}

function openBulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    document.getElementById('bulkDeleteIds').value = ids.join(',');
    document.getElementById('bulkDeleteCount').textContent = ids.length;
    openModal('bulkDeleteModal');
}
</script>
@endpush
