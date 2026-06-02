@extends('management.layout')
@section('subtitle', 'Products')

@section('content')
<x-management.page-header title="Products" subtitle="Manage your product catalog">
    <x-slot:actions>
        <a href="{{ route('management.products.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fi fi-rr-plus text-xs"></i> Add Product
        </a>
    </x-slot:actions>
</x-management.page-header>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Product</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Store</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden lg:table-cell">Section</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden lg:table-cell">Source</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Price</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Stock</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider hidden xl:table-cell">Code</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>
    @forelse($products ?? [] as $product)
    <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.products.show', $product) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $product->name }}</a>
        </td>
        <td class="px-5 py-3 hidden md:table-cell"><span class="text-sm text-slate-500">{{ $product->store?->name ?? 'N/A' }}</span></td>
        <td class="px-5 py-3 hidden lg:table-cell"><span class="text-sm text-slate-500">{{ $product->section?->name ?? '—' }}</span></td>
        <td class="px-5 py-3 hidden lg:table-cell"><span class="text-sm text-slate-500">{{ $product->section?->warehouse?->name ?? '—' }}</span></td>
        <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">{{ $displayPrices[$product->id] ?? '₦' . number_format($product->amount, 2) }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell">
            <span class="text-sm {{ $product->quantity <= 10 ? 'text-amber-600 font-semibold' : 'text-slate-600' }}">{{ $product->quantity }}</span>
        </td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$product->status" /></td>
        <td class="px-5 py-3 text-right hidden xl:table-cell"><span class="text-xs text-slate-400 font-mono">{{ $product->product_code }}</span></td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.products.show', $product) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View">
                    <i class="fi fi-rr-eye text-xs"></i>
                </a>
                <a href="{{ route('management.products.edit', $product) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </a>
                <form method="POST" action="{{ route('management.products.destroy', $product) }}" onsubmit="return confirm('Delete this product?')" class="inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete">
                        <i class="fi fi-rr-trash text-xs"></i>
                    </button>
                </form>
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
