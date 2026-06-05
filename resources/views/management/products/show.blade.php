@extends('management.layout')
@section('subtitle', $product->name)

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$product->name" subtitle="{{ $product->product_code }} · {{ $product->store?->name ?? 'No store' }}">
    <x-slot:actions>
        <x-management.status-badge :status="$product->status" />
        <a href="{{ route('management.products.edit', $product) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
            <i class="fi fi-rr-edit text-xs"></i> Edit
        </a>
    </x-slot:actions>
</x-management.page-header>

<div x-data="{ tab: 'overview' }">

    {{-- Tab Bar --}}
    <div class="flex items-center gap-1 mb-6 border-b border-slate-200">
        <button @click="tab = 'overview'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors" :class="tab === 'overview' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'">Overview</button>
        <button @click="tab = 'variants'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors" :class="tab === 'variants' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'">Variants <span class="text-xs text-slate-400 ml-1">({{ $product->variants->count() }})</span></button>
        <button @click="tab = 'media'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors" :class="tab === 'media' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'">Images <span class="text-xs text-slate-400 ml-1">({{ $product->images->count() }})</span></button>
    </div>

    {{-- Overview Tab --}}
    <div x-show="tab === 'overview'">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <x-management.card header="Product Details">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Store</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->store?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Category</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->category?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Section</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->section?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Source</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->section?->warehouse?->name ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Brand</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->brand ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Slug</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5 font-mono text-xs">{{ $product->slug }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Color</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->color ?? '—' }}</p>
                        </div>
                        @if($product->size)
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Size</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ rtrim(rtrim(number_format((float)$product->size, 2, '.', ''), '0'), '.') }} {{ $product->sizeUnit?->code }}</p>
                        </div>
                        @endif
                        @if($product->weight)
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Weight</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ rtrim(rtrim(number_format((float)$product->weight, 2, '.', ''), '0'), '.') }} {{ $product->weightUnit?->code }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Views</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ number_format((int)($product->views ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Tags</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->tags ?? '—' }}</p>
                        </div>
                    </div>
                    @if($product->description)
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <p class="text-xs text-slate-400 uppercase tracking-wider mb-2">Description</p>
                        <div class="text-sm text-slate-600 prose prose-sm max-w-none">{!! $product->description !!}</div>
                    </div>
                    @endif
                </x-management.card>

                @unless($product->has_variants)
                <x-management.card header="Pricing & Stock">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Price</p>
                            @php $sym = ($currencySymbols[$product->currency_id ?? 0]->symbol ?? ''); @endphp
                            @if(!is_null($product->discount_percentage) && $product->discount_percentage > 0)
                                @php $discAmt = (float)($product->amount ?? 0) * (1 - ((float)$product->discount_percentage / 100)); @endphp
                                <p class="text-base font-semibold text-slate-900 mt-0.5">
                                    {{ $sym }}{{ number_format($discAmt, 2) }}
                                    <span class="text-xs text-slate-400 line-through ml-1.5">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</span>
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 ml-1.5">-{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
                                </p>
                            @else
                                <p class="text-base font-semibold text-slate-900 mt-0.5">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Initial Stock</p>
                            <p class="text-base font-semibold text-slate-900 mt-0.5">{{ is_null($product->stock_quantity) ? '—' : number_format((int)$product->stock_quantity) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Remaining</p>
                            <p class="text-base font-semibold text-slate-900 mt-0.5">{{ number_format((int)($product->quantity ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Sold</p>
                            <p class="text-base font-semibold text-blue-600 mt-0.5">{{ number_format($product->soldQuantity()) }}</p>
                        </div>
                    </div>
                    @if(!is_null($product->stock_quantity))
                    @php
                        $pct = $product->stockPercentage();
                        $barColor = $pct > 50 ? 'bg-emerald-500' : ($pct > 20 ? 'bg-amber-500' : 'bg-red-500');
                    @endphp
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs text-slate-400">Stock level</span>
                        <span class="text-xs font-semibold text-slate-600">{{ $pct }}%
                            @if($pct <= 20)<span class="inline-flex items-center rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-600 ml-1">Low</span>
                            @elseif($pct <= 50)<span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 ml-1">Medium</span>
                            @else<span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 ml-1">Good</span>
                            @endif
                        </span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div class="h-full rounded-full transition-all {{ $barColor }}" style="width: {{ min($pct, 100) }}%"></div>
                    </div>
                    @endif
                    @if($product->bulk_quantity)
                    <div class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Bulk Min. Qty</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $product->bulk_quantity }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400 uppercase tracking-wider">Bulk Price</p>
                            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $sym }}{{ number_format((float)($product->bulk_price ?? 0), 2) }}</p>
                        </div>
                    </div>
                    @endif
                </x-management.card>
                @endunless
            </div>

            <div class="space-y-6">
                <x-management.card header="Settings">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-500">Has Variants</span>
                            <span class="text-sm font-medium {{ $product->has_variants ? 'text-blue-600' : 'text-slate-400' }}">{{ $product->has_variants ? 'Yes' : 'No' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-500">Featured</span>
                            <span class="text-sm font-medium {{ $product->featured ? 'text-amber-600' : 'text-slate-400' }}">{{ $product->featured ? 'Yes' : 'No' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-500">COD Available</span>
                            <span class="text-sm font-medium {{ $product->cod_available ? 'text-emerald-600' : 'text-slate-400' }}">{{ $product->cod_available ? 'Yes' : 'No' }}</span>
                        </div>
                        @if($product->discount_percentage)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-500">Discount</span>
                            <span class="text-sm font-medium text-amber-600">{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
                        </div>
                        @endif
                    </div>
                </x-management.card>

                <x-management.card header="Images">
                    @if($product->images->isNotEmpty())
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($product->images as $img)
                        <div class="border border-slate-200 rounded-lg overflow-hidden {{ $img->is_primary ? 'ring-2 ring-blue-400' : '' }}">
                            <img src="{{ asset('storage/'.$img->path) }}" class="w-full h-24 object-cover" alt="">
                            @if($img->is_primary)
                            <div class="bg-blue-50 text-blue-600 text-[10px] font-semibold text-center py-1">Primary</div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-slate-400 text-center py-4">No images uploaded</p>
                    @endif
                </x-management.card>

                <x-management.card>
                    <div class="space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-400">Created</span>
                            <span class="font-medium text-slate-700">{{ $product->created_at->format('d M Y, H:i') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Updated</span>
                            <span class="font-medium text-slate-700">{{ $product->updated_at->diffForHumans() }}</span>
                        </div>
                    </div>
                </x-management.card>
            </div>
        </div>
    </div>

    {{-- Variants Tab --}}
    <div x-show="tab === 'variants'">
        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-slate-500">
                @if($priceInfoSymbol)
                Price range: <span class="font-semibold text-slate-800">{{ $priceInfoSymbol }}</span>
                @endif
            </p>
        </div>
        <x-management.data-table>
            <x-slot:header>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">SKU</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Size</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Weight</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Color</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Qty</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Price</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
            </x-slot:header>
            @forelse($product->variants as $v)
            <tr class="hover:bg-slate-50/50 transition-colors">
                <td class="px-5 py-3"><span class="text-sm font-mono text-slate-600">{{ $v->sku ?? '—' }}</span></td>
                <td class="px-5 py-3">
                    @if(!is_null($v->size))
                    <span class="text-sm text-slate-700">{{ rtrim(rtrim(number_format((float)$v->size, 2, '.', ''), '0'), '.') }} {{ $v->sizeUnit?->code }}</span>
                    @else <span class="text-sm text-slate-300">—</span> @endif
                </td>
                <td class="px-5 py-3 hidden sm:table-cell">
                    @if(!is_null($v->weight))
                    <span class="text-sm text-slate-700">{{ rtrim(rtrim(number_format((float)$v->weight, 2, '.', ''), '0'), '.') }} {{ $v->weightUnit?->code }}</span>
                    @else <span class="text-sm text-slate-300">—</span> @endif
                </td>
                <td class="px-5 py-3"><span class="text-sm text-slate-700">{{ $v->color ?? '—' }}</span></td>
                <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">{{ (int)$v->quantity }}</span></td>
                <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">{{ ($currencySymbols[$v->currency_id ?? 0]->symbol ?? '') }}{{ number_format((float)$v->amount, 2) }}</span></td>
                <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$v->status" /></td>
            </tr>
            @empty
            <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-400">No variants created.</td></tr>
            @endforelse
        </x-management.data-table>
    </div>

    {{-- Images & Meta Tab --}}
    <div x-show="tab === 'media'">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <x-management.card header="Product Images">
                    @if($product->images->isNotEmpty())
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach($product->images as $img)
                        <div class="border border-slate-200 rounded-xl overflow-hidden {{ $img->is_primary ? 'ring-2 ring-blue-400' : '' }}">
                            <img src="{{ asset('storage/'.$img->path) }}" class="w-full h-32 object-cover" alt="">
                            <div class="px-3 py-2 flex items-center justify-between">
                                <span class="text-[10px] text-slate-400 font-mono">IMG{{ $img->id }}</span>
                                @if($img->is_primary)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600">Primary</span>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-slate-400 text-center py-8">No images uploaded yet.</p>
                    @endif
                </x-management.card>
            </div>
            <div class="space-y-6">
                <x-management.card header="Metadata">
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-400">Code</span>
                            <span class="font-medium text-slate-700 font-mono">{{ $product->product_code }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Slug</span>
                            <span class="font-medium text-slate-700 font-mono text-xs">{{ $product->slug }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Created</span>
                            <span class="font-medium text-slate-700">{{ $product->created_at->format('d M Y') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Updated</span>
                            <span class="font-medium text-slate-700">{{ $product->updated_at->diffForHumans() }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Views</span>
                            <span class="font-medium text-slate-700">{{ number_format((int)($product->views ?? 0)) }}</span>
                        </div>
                    </div>
                </x-management.card>
            </div>
        </div>
    </div>

</div>
@endsection
