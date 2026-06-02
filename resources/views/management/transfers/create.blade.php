@extends('management.layout')
@section('subtitle', 'New Stock Transfer')

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('management.transfers.index') }}" class="text-slate-400 hover:text-slate-600">
        <i class="fi fi-rr-arrow-left"></i>
    </a>
    <h2 class="text-lg font-semibold text-slate-900">New Stock Transfer</h2>
</div>

<form method="POST" action="{{ route('management.transfers.store') }}" x-data="transferForm()">
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
        <div class="lg:col-span-2 space-y-6">

            {{-- Locations --}}
            <x-management.card header="Source & Destination">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="from_warehouse_id" label="From Warehouse" type="select" required :error="$errors->first('from_warehouse_id')">
                        <option value="">Select warehouse</option>
                        @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}" @selected(old('from_warehouse_id', $preSelectedWarehouse?->id) == $wh->id)>{{ $wh->name }}</option>
                        @endforeach
                    </x-management.form-input>
                    <x-management.form-input name="to_store_id" label="To Store" type="select" required :error="$errors->first('to_store_id')">
                        <option value="">Select store</option>
                        @foreach($stores as $s)
                        <option value="{{ $s->id }}" @selected(old('to_store_id') == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </x-management.form-input>
                </div>
            </x-management.card>

            {{-- Items --}}
            <x-management.card>
                <x-slot:header>
                    <div class="flex items-center justify-between">
                        <span>Products</span>
                        <button type="button" @click="addItem()" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                            <i class="fi fi-rr-plus text-xs"></i> Add Product
                        </button>
                    </div>
                </x-slot:header>

                <template x-if="items.length === 0">
                    <div class="px-5 py-8 text-center">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3">
                            <i class="fi fi-rr-cube"></i>
                        </span>
                        <p class="text-sm font-medium text-slate-700 mb-1">No products added</p>
                        <p class="text-xs text-slate-400">Add products to request from the warehouse</p>
                    </div>
                </template>

                <template x-for="(item, index) in items" :key="item.id">
                    <div class="flex items-center gap-3 py-3 border-b border-slate-50 last:border-b-0 -mx-5 px-5">
                        <select :name="'items[' + index + '][product_id]'" class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required>
                            <option value="">Select product</option>
                            @foreach($products as $p)
                            <option value="{{ $p->id }}" data-store="{{ $p->store_id }}">{{ $p->name }} ({{ $p->product_code }})</option>
                            @endforeach
                        </select>
                        <input type="number" :name="'items[' + index + '][quantity]'" min="1" placeholder="Qty" class="w-20 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" required x-model="item.quantity">
                        <button type="button" @click="removeItem(index)" class="p-1.5 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg shrink-0">
                            <i class="fi fi-rr-trash text-xs"></i>
                        </button>
                    </div>
                </template>
            </x-management.card>

        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-management.card header="Notes">
                <textarea name="notes" rows="3" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Optional notes for the warehouse manager...">{{ old('notes') }}</textarea>
                @error('notes')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </x-management.card>

            <x-management.card>
                <div class="space-y-2">
                    <button type="submit" name="submitted" value="1" class="block w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                        Submit for Approval
                    </button>
                    <button type="submit" name="draft" value="1" class="block w-full py-2 text-sm font-medium text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                        Save as Draft
                    </button>
                    <a href="{{ route('management.transfers.index') }}" class="block w-full py-2 text-sm font-medium text-slate-500 text-center hover:text-slate-700">Cancel</a>
                </div>
            </x-management.card>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('transferForm', () => ({
        items: [],
        itemId: 0,

        addItem() {
            this.items.push({ id: ++this.itemId, quantity: 1 });
        },

        removeItem(index) {
            this.items.splice(index, 1);
        },
    }));
});
</script>
@endpush
