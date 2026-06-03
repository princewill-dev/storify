@extends('management.layout')
@section('subtitle', 'Edit ' . $product->name)

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('management.products.show', $product) }}" class="text-slate-400 hover:text-slate-600">
        <i class="fi fi-rr-arrow-left"></i>
    </a>
    <div>
        <h2 class="text-lg font-semibold text-slate-900">Edit Product</h2>
        <p class="text-xs text-slate-400">{{ $product->name }} · {{ $product->product_code }}</p>
    </div>
</div>

@if($errors->any())
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">
    <ul class="list-disc pl-4 space-y-1">
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<form method="post" action="{{ route('management.products.update', $product) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Main Content --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Organization --}}
            <x-management.card header="Organization">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="store_id" label="Store" type="select" :error="$errors->first('store_id')">
                        <option value="">None (warehouse-only)</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}" @selected(old('store_id', $product->store_id) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="category_id" label="Category" type="select" :error="$errors->first('category_id')">
                        <option value="">—</option>
                        @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="section_id" label="Section" type="select" :error="$errors->first('section_id')">
                        <option value="">None</option>
                        @foreach(($sections ?? []) as $sec)
                        <option value="{{ $sec->id }}" @selected(old('section_id', $product->section_id) == $sec->id)>{{ $sec->name }} ({{ $sec->warehouse?->name }})</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="warehouse_id" label="Warehouse" type="select" :error="$errors->first('warehouse_id')">
                        <option value="">None (auto-set from section)</option>
                        @foreach(($warehouses ?? []) as $wh)
                        <option value="{{ $wh->id }}" @selected(old('warehouse_id', $product->warehouse_id) == $wh->id)>{{ $wh->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="status" label="Status" type="select" required :error="$errors->first('status')">
                        <option value="active" @selected(old('status', $product->status) == 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $product->status) == 'inactive')>Inactive</option>
                    </x-management.form-input>
                </div>
            </x-management.card>

            {{-- Basic Info --}}
            <x-management.card header="Basic Information">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="name" label="Product Name" :value="old('name', $product->name)" required :error="$errors->first('name')" />
                    <x-management.form-input name="brand" label="Brand" :value="old('brand', $product->brand)" placeholder="Apple, Samsung..." :error="$errors->first('brand')" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div>
                        <x-management.form-input name="quantity" label="Quantity (remaining)" type="number" :value="old('quantity', $product->quantity)" required :error="$errors->first('quantity')" />
                    </div>
                    <div>
                        <x-management.form-input name="stock_quantity" label="Initial Stock" type="number" :value="old('stock_quantity', $product->stock_quantity)" placeholder="e.g. 100" :error="$errors->first('stock_quantity')" />
                        @if(!is_null($product->stock_quantity))
                        <p class="text-xs text-slate-400 mt-1">Sold: {{ number_format($product->soldQuantity()) }} unit(s)</p>
                        @else
                        <p class="text-xs text-slate-400 mt-1">Total units you started with. Sold = Initial − Remaining.</p>
                        @endif
                    </div>
                </div>
            </x-management.card>

            {{-- Pricing & Attributes --}}
            <x-management.card header="Pricing & Attributes">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input type="number" name="amount" step="0.01" min="0.01" value="{{ old('amount', number_format($product->amount, 2, '.', '')) }}" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm {{ $errors->first('amount') ? 'border-red-300' : '' }}" required>
                            <select name="currency_id" class="w-24 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                @foreach(($currencies ?? []) as $cur)
                                <option value="{{ $cur->id }}" @selected(old('currency_id', $product->currency_id ?? ($defaultCurrencyId ?? null)) == $cur->id)>{{ $cur->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('amount')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <x-management.form-input name="discount_percentage" label="Discount (%)" type="number" step="0.01" min="0" max="100" :value="old('discount_percentage', $product->discount_percentage)" placeholder="e.g. 10" :error="$errors->first('discount_percentage')" />
                    <x-management.form-input name="bulk_quantity" label="Bulk Min. Qty" type="number" :value="old('bulk_quantity', $product->bulk_quantity)" placeholder="e.g. 10" :error="$errors->first('bulk_quantity')" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Size</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="size" value="{{ old('size', $product->size) }}" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="e.g. 15">
                            <select name="size_unit_id" class="w-24 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                <option value="">—</option>
                                @foreach($sizeUnits as $u)
                                <option value="{{ $u->id }}" @selected(old('size_unit_id', $product->size_unit_id) == $u->id)>{{ $u->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Weight</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.01" min="0" name="weight" value="{{ old('weight', $product->weight) }}" class="flex-1 rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="e.g. 1.2">
                            <select name="weight_unit_id" class="w-24 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                <option value="">—</option>
                                @foreach($weightUnits as $u)
                                <option value="{{ $u->id }}" @selected(old('weight_unit_id', $product->weight_unit_id) == $u->id)>{{ $u->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <x-management.form-input name="color" label="Color" :value="old('color', $product->color)" placeholder="e.g. Space Gray" :error="$errors->first('color')" />
                    <x-management.form-input name="tags" label="Tags" :value="old('tags', $product->tags)" placeholder="comma separated e.g. laptop, pro, 2024" :error="$errors->first('tags')" />
                </div>
            </x-management.card>

            {{-- Variants --}}
            <x-management.card>
                <x-slot:header>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="has_variants" value="1" id="hasVariantsToggle" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" {{ old('has_variants', $product->has_variants) ? 'checked' : '' }}>
                        <span class="text-sm font-medium text-slate-700">Has Variants</span>
                    </label>
                </x-slot:header>

                <div id="variantsSection" style="display: none;">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-sm text-slate-500">Define multiple sizes, weights, colors with their own price and stock.</p>
                        <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors" onclick="addVariantRow()">
                            <i class="fi fi-rr-plus text-xs"></i> Add Variant
                        </button>
                    </div>

                    <div id="variantsContainer" class="space-y-3">
                        @php($oldVariants = collect(old('variants', $product->variants?->map(function($v){return [
                            'id'=>$v->id, 'size'=>$v->size, 'size_unit_id'=>$v->size_unit_id,
                            'weight'=>$v->weight, 'weight_unit_id'=>$v->weight_unit_id, 'color'=>$v->color,
                            'sku'=>$v->sku, 'quantity'=>$v->quantity, 'amount'=>$v->amount,
                            'currency_id'=>$v->currency_id, 'status'=>$v->status, 'featured'=>$v->featured,
                        ];})) ))
                        @foreach(($oldVariants ?? []) as $i => $v)
                        <div class="variant-row border border-slate-200 rounded-xl p-5">
                            <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Size</label>
                                        <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][size]" value="{{ $v['size'] ?? '' }}" placeholder="e.g. 15">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Unit</label>
                                        <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][size_unit_id]">
                                            <option value="">—</option>
                                            @foreach(($sizeUnits ?? []) as $u)
                                            <option value="{{ $u->id }}" @selected(($v['size_unit_id'] ?? '') == $u->id)>{{ $u->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Weight</label>
                                        <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][weight]" value="{{ $v['weight'] ?? '' }}" placeholder="e.g. 1.2">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Unit</label>
                                        <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][weight_unit_id]">
                                            <option value="">—</option>
                                            @foreach(($weightUnits ?? []) as $u)
                                            <option value="{{ $u->id }}" @selected(($v['weight_unit_id'] ?? '') == $u->id)>{{ $u->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Color</label>
                                    <input type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][color]" value="{{ $v['color'] ?? '' }}" placeholder="e.g. Red">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">SKU</label>
                                    <input type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][sku]" value="{{ $v['sku'] ?? '' }}" placeholder="optional">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Quantity <span class="text-red-500">*</span></label>
                                    <input type="number" min="1" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][quantity]" value="{{ $v['quantity'] ?? '' }}" required>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Amount <span class="text-red-500">*</span></label>
                                    <div class="flex gap-1">
                                        <input type="number" step="0.01" min="0.01" class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][amount]" value="{{ isset($v['amount']) ? number_format($v['amount'], 2, '.', '') : '' }}" required>
                                        <select class="w-20 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][currency_id]">
                                            @foreach(($currencies ?? []) as $cur)
                                            <option value="{{ $cur->id }}" @selected(($v['currency_id'] ?? ($defaultCurrencyId ?? null)) == $cur->id)>{{ $cur->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Status</label>
                                    <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="variants[{{ $i }}][status]">
                                        <option value="active" @selected(($v['status'] ?? 'active') === 'active')>Active</option>
                                        <option value="inactive" @selected(($v['status'] ?? '') === 'inactive')>Inactive</option>
                                    </select>
                                </div>
                                <div class="flex items-end justify-between pt-2 border-t border-slate-100">
                                    @php($fid = 'variant-featured-'.$i)
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="variants[{{ $i }}][featured]" value="1" id="{{ $fid }}" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" @checked(!empty($v['featured']))>
                                        <span class="text-xs text-slate-600">Featured</span>
                                    </label>
                                    <button type="button" class="text-xs text-red-500 hover:text-red-700 font-medium" onclick="removeVariantRow(this)">Remove</button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <template id="variantRowTemplate">
                        <div class="variant-row border border-slate-200 rounded-xl p-5">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Size</label>
                                        <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[size]" placeholder="e.g. 15">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Unit</label>
                                        <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[size_unit_id]">
                                            <option value="">—</option>
                                            @foreach(($sizeUnits ?? []) as $u)
                                            <option value="{{ $u->id }}">{{ $u->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Weight</label>
                                        <input type="number" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[weight]" placeholder="e.g. 1.2">
                                    </div>
                                    <div class="w-24">
                                        <label class="block text-[11px] font-medium text-slate-500 mb-1">Unit</label>
                                        <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[weight_unit_id]">
                                            <option value="">—</option>
                                            @foreach(($weightUnits ?? []) as $u)
                                            <option value="{{ $u->id }}">{{ $u->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Color</label>
                                    <input type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[color]" placeholder="e.g. Red">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">SKU</label>
                                    <input type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[sku]" placeholder="optional">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Quantity <span class="text-red-500">*</span></label>
                                    <input type="number" min="1" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[quantity]" required>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Amount <span class="text-red-500">*</span></label>
                                    <div class="flex gap-1">
                                        <input type="number" step="0.01" min="0.01" class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[amount]" required>
                                        <select class="w-20 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[currency_id]">
                                            @foreach(($currencies ?? []) as $cur)
                                            <option value="{{ $cur->id }}" @selected(($defaultCurrencyId ?? null) == $cur->id)>{{ $cur->code }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Status</label>
                                    <select class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" name="__NAME__[status]">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="flex items-end justify-between pt-2 border-t border-slate-100">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="__NAME__[featured]" value="1" id="__ID__" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                        <span class="text-xs text-slate-600">Featured</span>
                                    </label>
                                    <button type="button" class="text-xs text-red-500 hover:text-red-700 font-medium" onclick="removeVariantRow(this)">Remove</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </x-management.card>

            {{-- Images --}}
            <x-management.card header="Existing Images">
                @forelse($product->images as $img)
                <div class="inline-flex flex-col items-center gap-1 border border-slate-200 rounded-xl p-3 mr-3 mb-3 w-40 align-top">
                    <img src="{{ asset('storage/'.$img->path) }}" alt="" class="w-full h-24 object-cover rounded-lg">
                    <label class="flex items-center gap-1.5 cursor-pointer text-xs mt-1">
                        <input type="radio" name="primary_image_id" value="{{ $img->id }}" class="border-slate-300 text-blue-600 focus:ring-blue-500" @checked(old('primary_image_id', $product->primaryImage()?->id) == $img->id)>
                        <span class="text-slate-600">Primary</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer text-xs">
                        <input type="checkbox" name="delete_image_ids[]" value="{{ $img->id }}" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                        <span class="text-red-500">Delete</span>
                    </label>
                </div>
                @empty
                <p class="text-sm text-slate-400 py-2">No images uploaded yet.</p>
                @endforelse
            </x-management.card>

            <x-management.card header="Add Images">
                <input type="file" name="images[]" multiple accept="image/*" class="block w-full text-sm text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                <p class="text-xs text-slate-400 mt-1.5">Upload additional product images. PNG, JPG, WEBP.</p>
            </x-management.card>

        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">

            <x-management.card header="Settings">
                <div class="space-y-3">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="cod_available" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('cod_available', $product->cod_available) ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">COD Available</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="featured" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('featured', $product->featured) ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">Featured Product</span>
                    </label>
                </div>
            </x-management.card>

            <x-management.card header="Description">
                <input id="product-description" type="hidden" name="description" value="{{ old('description', $product->description) }}">
                <trix-editor input="product-description" class="trix-content"></trix-editor>
                @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </x-management.card>

            <x-management.card>
                <div class="space-y-2">
                    <p class="text-xs text-slate-400 text-center">Last updated {{ $product->updated_at->diffForHumans() }}</p>
                    <button type="submit" class="block w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                    <a href="{{ route('management.products.show', $product) }}" class="block w-full py-2 text-sm font-medium text-slate-600 text-center border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">Cancel</a>
                </div>
            </x-management.card>

        </div>
    </div>
</form>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.min.css">
<style>
    trix-toolbar { --tw-bg-opacity: 1; background-color: rgb(248 250 252); border: 1px solid rgb(226 232 240); border-radius: 0.5rem 0.5rem 0 0; }
    trix-editor { min-height: 150px; border: 1px solid rgb(226 232 240); border-top: none; border-radius: 0 0 0.5rem 0.5rem; padding: 0.75rem; font-size: 0.875rem; }
    trix-editor:focus { outline: none; border-color: rgb(107 114 128); }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.umd.min.js"></script>
<script>
(function(){
  const toggle = document.getElementById('hasVariantsToggle');
  const section = document.getElementById('variantsSection');
  const container = document.getElementById('variantsContainer');
  const tmpl = document.getElementById('variantRowTemplate');

  function setBaseFieldsDisabled(disabled){
    const baseNames = ['quantity','stock_quantity','amount','size','size_unit_id','weight','weight_unit_id','color','currency_id'];
    baseNames.forEach(n => {
      const el = document.querySelector(`[name="${n}"]`);
      if (el) { el.disabled = disabled; }
    });
  }

  function showVariants(on){
    section.style.display = on ? 'block' : 'none';
    setBaseFieldsDisabled(on);
  }

  window.addVariantRow = function(){
    const idx = container.querySelectorAll('.variant-row').length;
    const html = tmpl.innerHTML
      .replaceAll('__NAME__', `variants[${idx}]`)
      .replaceAll('__ID__', `variant-featured-${idx}`);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const node = wrapper.firstElementChild;
    container.appendChild(node);
  };

  window.removeVariantRow = function(btn){
    const row = btn.closest('.variant-row');
    if (row) row.remove();
    Array.from(container.querySelectorAll('.variant-row')).forEach((rowEl, i) => {
      rowEl.querySelectorAll('input, select, textarea, label').forEach(el => {
        if (el.name && el.name.includes('variants[')) {
          el.name = el.name.replace(/variants\[[0-9]+\]/, `variants[${i}]`);
        }
        if (el.htmlFor && el.htmlFor.startsWith('variant-featured-')) {
          el.htmlFor = `variant-featured-${i}`;
        }
        if (el.id && el.id.startsWith('variant-featured-')) {
          el.id = `variant-featured-${i}`;
        }
      });
    });
  };

  if (toggle) {
    toggle.addEventListener('change', function(){
      const on = !!this.checked;
      showVariants(on);
      if (on && container.querySelectorAll('.variant-row').length === 0) {
        addVariantRow();
      }
    });
    const initOn = !!toggle.checked;
    showVariants(initOn);
  }
})();
</script>
@endpush
