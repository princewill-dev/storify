@extends('management.layout')
@section('subtitle', 'Create Product')

@section('content')
<div class="flex items-center gap-3 mb-6">
    @php
        $backUrl = route('management.products.index');
        if ($selectedSectionId) {
            $preselectedSection = \App\Models\Section::with('warehouse')->find($selectedSectionId);
            if ($preselectedSection?->warehouse) {
                $backUrl = route('management.sections.show', [$preselectedSection->warehouse, $preselectedSection]);
            }
        }
    @endphp
    <a href="{{ $backUrl }}" class="text-slate-400 hover:text-slate-600">
        <i class="fi fi-rr-arrow-left"></i>
    </a>
    <h2 class="text-lg font-semibold text-slate-900">Add Product</h2>
</div>

<form action="{{ route('management.products.store') }}" method="POST" enctype="multipart/form-data">
    @csrf

    @if($errors->any())
    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">
        <ul class="list-disc pl-4 space-y-1">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Main Content --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Basic Info --}}
            <x-management.card header="Basic Information">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="name" label="Product Name" placeholder="Enter product name" required :value="old('name')" :error="$errors->first('name')" />
                    <x-management.form-input name="brand" label="Brand" placeholder="Brand name" :value="old('brand')" :error="$errors->first('brand')" />
                </div>
                <div class="mt-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                    <textarea name="description" rows="3" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm" placeholder="Describe the product...">{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </x-management.card>

            {{-- Organization --}}
            <x-management.card header="Organization">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-management.form-input name="store_id" label="Store" type="select" :error="$errors->first('store_id')">
                        <option value="">None (warehouse-only)</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}" @selected(old('store_id', $selectedStoreId) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="category_id" label="Category" type="select" :error="$errors->first('category_id')">
                        <option value="">Select category</option>
                        @foreach($categories ?? [] as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="section_id" label="Section" type="select" :error="$errors->first('section_id')">
                        <option value="">None</option>
                        @foreach($sections as $sec)
                        <option value="{{ $sec->id }}" @selected(old('section_id', $selectedSectionId) == $sec->id)>{{ $sec->name }} ({{ $sec->warehouse?->name }})</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="warehouse_id" label="Warehouse" type="select" :error="$errors->first('warehouse_id')">
                        <option value="">None (auto-set from section)</option>
                        @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}" @selected(old('warehouse_id') == $wh->id)>{{ $wh->name }}</option>
                        @endforeach
                    </x-management.form-input>
                </div>
            </x-management.card>

            {{-- Pricing --}}
            <x-management.card header="Pricing">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-management.form-input name="amount" label="Price" type="number" step="0.01" placeholder="0.00" required :value="old('amount')" :error="$errors->first('amount')" />
                    <x-management.form-input name="discount_percentage" label="Discount %" type="number" step="0.01" min="0" max="100" placeholder="0" :value="old('discount_percentage')" :error="$errors->first('discount_percentage')" />
                    <x-management.form-input name="currency_id" label="Currency" type="select" :error="$errors->first('currency_id')">
                        <option value="">Default</option>
                        @foreach($currencies as $cur)
                        <option value="{{ $cur->id }}" @selected(old('currency_id', $defaultCurrencyId) == $cur->id)>{{ $cur->code }} ({{ $cur->symbol }})</option>
                        @endforeach
                    </x-management.form-input>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 border-t border-slate-100 pt-4">
                    <x-management.form-input name="bulk_quantity" label="Bulk Min. Quantity" type="number" placeholder="e.g. 10" :value="old('bulk_quantity')" :error="$errors->first('bulk_quantity')" />
                    <x-management.form-input name="bulk_price" label="Bulk Price" type="number" step="0.01" placeholder="Discounted bulk price" :value="old('bulk_price')" :error="$errors->first('bulk_price')" />
                </div>
            </x-management.card>

            {{-- Inventory --}}
            <x-management.card header="Inventory">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="quantity" label="Stock Quantity" type="number" placeholder="0" required :value="old('quantity')" :error="$errors->first('quantity')" />
                    <x-management.form-input name="stock_quantity" label="Initial Stock" type="number" placeholder="Total initial stock" :value="old('stock_quantity')" :error="$errors->first('stock_quantity')" />
                </div>
            </x-management.card>

            {{-- Attributes --}}
            <x-management.card header="Attributes">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-management.form-input name="size" label="Size" type="number" step="0.01" placeholder="e.g. 42" :value="old('size')" :error="$errors->first('size')" />
                    <x-management.form-input name="size_unit_id" label="Size Unit" type="select" :error="$errors->first('size_unit_id')">
                        <option value="">Select unit</option>
                        @foreach($sizeUnits as $su)
                        <option value="{{ $su->id }}" @selected(old('size_unit_id') == $su->id)>{{ $su->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="color" label="Color" placeholder="e.g. Red, Blue" :value="old('color')" :error="$errors->first('color')" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <x-management.form-input name="weight" label="Weight" type="number" step="0.01" placeholder="e.g. 1.5" :value="old('weight')" :error="$errors->first('weight')" />
                    <x-management.form-input name="weight_unit_id" label="Weight Unit" type="select" :error="$errors->first('weight_unit_id')">
                        <option value="">Select unit</option>
                        @foreach($weightUnits as $wu)
                        <option value="{{ $wu->id }}" @selected(old('weight_unit_id') == $wu->id)>{{ $wu->name }}</option>
                        @endforeach
                    </x-management.form-input>
                </div>
                <div class="mt-4">
                    <x-management.form-input name="tags" label="Tags" placeholder="Comma-separated tags (e.g. electronics, sale, new)" :value="old('tags')" :error="$errors->first('tags')" />
                </div>
            </x-management.card>

            {{-- Images --}}
            <x-management.card header="Product Images">
                <div class="rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                    <svg class="mx-auto h-10 w-10 text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="text-sm text-slate-500 mb-1">Drag & drop images or <label class="font-medium text-blue-600 hover:text-blue-700 cursor-pointer">browse<input type="file" name="images[]" multiple accept=".png,.jpg,.jpeg,.webp" class="hidden"></label></p>
                    <p class="text-xs text-slate-400">Up to 5 images — PNG, JPG, WEBP</p>
                </div>
                @error('images.*')<p class="text-xs text-red-500 mt-2">{{ $message }}</p>@enderror
            </x-management.card>

        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">

            <x-management.card header="Settings">
                <div class="space-y-3">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="status" value="inactive">
                        <input type="checkbox" name="status" value="active" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('status', 'active') === 'active' ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">Active</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="featured" value="0">
                        <input type="checkbox" name="featured" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('featured') ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">Featured</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="cod_available" value="0">
                        <input type="checkbox" name="cod_available" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('cod_available', true) ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">COD Available</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="has_variants" value="0">
                        <input type="checkbox" name="has_variants" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" {{ old('has_variants') ? 'checked' : '' }}>
                        <span class="text-sm text-slate-700">Has Variants</span>
                    </label>
                </div>
            </x-management.card>

            @if($selectedSectionId)
            <x-management.card header="Section Context">
                @php($preselectedSection = \App\Models\Section::find($selectedSectionId))
                @if($preselectedSection)
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-xs text-slate-400">Section</span>
                        <span class="text-xs font-medium text-slate-700">{{ $preselectedSection->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-xs text-slate-400">Warehouse</span>
                        <span class="text-xs font-medium text-slate-700">{{ $preselectedSection->warehouse?->name ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-xs text-slate-400">Code</span>
                        <span class="text-xs font-medium text-slate-700 font-mono">{{ $preselectedSection->section_code }}</span>
                    </div>
                </div>
                @endif
            </x-management.card>
            @endif

            <x-management.card>
                <div class="space-y-2">
                    <button type="submit" class="block w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                        <i class="fi fi-rr-plus text-xs mr-1"></i> Create Product
                    </button>
                    <a href="{{ $backUrl }}" class="block w-full py-2 text-sm font-medium text-slate-600 text-center border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">Cancel</a>
                </div>
            </x-management.card>

        </div>
    </div>
</form>
@endsection
