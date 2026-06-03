@extends('management.layout')
@section('subtitle', $warehouse->name . ' — ' . $section->name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('management.sections.index', $warehouse) }}" class="text-slate-400 hover:text-slate-600">
            <i class="fi fi-rr-arrow-left"></i>
        </a>
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $section->name }}</h2>
            <p class="text-xs text-slate-400">{{ $section->section_code }} · {{ $warehouse->name }} · {{ $stats['count'] }} products</p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <x-management.status-badge :status="$section->isActive() ? 'active' : 'inactive'" />
        <a href="{{ route('management.sections.edit', [$warehouse, $section]) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 rounded-lg transition-colors">
            <i class="fi fi-rr-edit text-xs"></i> Edit
        </a>
        @if($stats['count'] === 0)
        <form method="POST" action="{{ route('management.sections.destroy', [$warehouse, $section]) }}" onsubmit="return confirm('Delete this section?')" class="inline">
            @csrf @method('DELETE')
            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 rounded-lg transition-colors">
                <i class="fi fi-rr-trash text-xs"></i> Delete
            </button>
        </form>
        @endif
        <a href="{{ route('management.products.create', ['section_id' => $section->section_code]) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Product
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card :value="$stats['count']" label="Products" icon="fi-rr-cube" />
    <x-management.metric-card :value="$stats['active']" label="Active" icon="fi-rr-check-circle" />
    <x-management.metric-card :value="'₦' . number_format($stats['value'], 2)" label="Total Value" icon="fi-rr-chart-histogram" />
    <x-management.metric-card :value="$stats['outOfStock']" label="Out of Stock" icon="fi-rr-exclamation-triangle" />
</div>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Product</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Code</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Price</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Stock</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>

    @forelse($products as $product)
    <tr class="hover:bg-slate-50/50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.products.show', $product) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700 truncate max-w-[200px] block">{{ $product->name }}</a>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell">
            <span class="text-xs text-slate-400 font-mono">{{ $product->product_code }}</span>
        </td>
        <td class="px-5 py-3 text-right">
            <span class="text-sm font-semibold text-slate-700">{{ $product->amount ? '₦' . number_format($product->amount, 2) : '—' }}</span>
        </td>
        <td class="px-5 py-3 text-right hidden md:table-cell">
            @if($product->stock_quantity && $product->stock_quantity > 0)
            <div class="flex items-center justify-end gap-2">
                <div class="w-20 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                    <div class="h-full rounded-full {{ $product->stockPercentage() > 50 ? 'bg-emerald-500' : ($product->stockPercentage() > 20 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ min($product->stockPercentage(), 100) }}%"></div>
                </div>
                <span class="text-xs text-slate-400">{{ $product->quantity }}</span>
            </div>
            @else
            <span class="text-xs text-slate-300">—</span>
            @endif
        </td>
        <td class="px-5 py-3 text-center hidden sm:table-cell">
            <x-management.status-badge :status="$product->status" />
        </td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.products.show', $product) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View">
                    <i class="fi fi-rr-eye text-xs"></i>
                </a>
                <a href="{{ route('management.products.edit', $product) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </a>
            </div>
        </td>
    </tr>
    @empty
    <tr>
        <td colspan="6" class="px-5 py-12 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3">
                <i class="fi fi-rr-cube"></i>
            </span>
            <p class="text-sm font-medium text-slate-700 mb-1">No products in this section</p>
            <p class="text-xs text-slate-400">Assign products to this section from the product editor</p>
        </td>
    </tr>
    @endforelse
</x-management.data-table>

@if($products->hasPages())
<div class="mt-4">
    {{ $products->links() }}
</div>
@endif
@endsection
