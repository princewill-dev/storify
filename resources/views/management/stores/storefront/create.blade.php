@extends('management.layout')
@section('subtitle', 'Create Storefront · ' . $store->name)

@push('styles')
<style>
    .template-card {
        border: 2px solid var(--border, #e2e8f0); border-radius: 14px; padding: 20px;
        cursor: pointer; transition: all 0.2s; position: relative;
    }
    .template-card:hover { border-color: #94a3b8; }
    .template-card.selected { border-color: #2563eb; background: #f8faff; }
    .template-card .check { display: none; }
    .template-card.selected .check { display: flex; }
    .template-preview {
        height: 120px; border-radius: 10px; margin-bottom: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 13px; font-weight: 600;
    }
</style>
@endpush

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Create Storefront" subtitle="Enable an online storefront for {{ $store->name }}" />

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <form action="{{ route('management.stores.storefront.store', $store) }}" method="POST" id="storefrontForm">
            @csrf

            @if($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 mb-6">
                <ul class="list-disc pl-4 space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
            @endif

            {{-- Template Selection --}}
            <x-management.card header="Choose a Template">
                <p class="text-sm text-slate-500 mb-4">Select how your storefront will look. You can customize it later.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach($templates as $tpl)
                    <label class="template-card {{ $loop->first ? 'selected' : '' }}" id="tpl-{{ $tpl['id'] }}">
                        <input type="radio" name="template" value="{{ $tpl['id'] }}" class="hidden" {{ $loop->first ? 'checked' : '' }}>
                        <div class="template-preview" style="background:{{ $tpl['color'] }}15;color:{{ $tpl['color'] }};">
                            {{ $tpl['name'] }} Preview
                        </div>
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800">{{ $tpl['name'] }}</h3>
                                <p class="text-xs text-slate-500 mt-0.5">{{ $tpl['description'] }}</p>
                            </div>
                            <span class="check w-6 h-6 rounded-full bg-blue-600 text-white items-center justify-center shrink-0 ml-3">
                                <i class="fi fi-rr-check text-[10px]"></i>
                            </span>
                        </div>
                    </label>
                    @endforeach
                </div>
            </x-management.card>

            {{-- Store Details --}}
            <x-management.card header="Store Details">
                <div class="space-y-4">
                    <x-management.form-input name="store_name" label="Store Name" :value="old('store_name', $store->name)" required :error="$errors->first('store_name')"
                        placeholder="eg: Swift Essentials" />

                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                        <div class="flex items-center gap-2">
                            <i class="fi fi-rr-globe text-slate-400 text-sm shrink-0"></i>
                            <span id="slugUrl" class="text-sm font-medium text-slate-700 truncate">Enter a store name above</span>
                            <span id="slugStatus" class="text-xs font-medium shrink-0"></span>
                        </div>
                        <input type="hidden" name="slug" id="slugInput" value="{{ old('slug') }}">
                    </div>
                    @error('slug')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </x-management.card>

            {{-- Delivery Setup --}}
            <x-management.card header="Delivery Options">
                <div class="space-y-4">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_nationwide" value="1" onchange="toggleNationwide()"
                               class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                        <div>
                            <span class="text-sm font-medium text-slate-700">Nationwide Delivery</span>
                            <p class="text-xs text-slate-400 mt-0.5">Flat rate delivery to all states in Nigeria</p>
                        </div>
                    </label>
                    <div id="nationwideFields" class="hidden pl-7 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Delivery Fee (₦)</label>
                                <input type="number" name="nationwide_fee" min="0" value="{{ old('nationwide_fee') }}"
                                       class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm" placeholder="e.g. 2000">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Delivery Days</label>
                                <input type="number" name="nationwide_days" min="1" value="{{ old('nationwide_days') }}"
                                       class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm" placeholder="e.g. 3">
                            </div>
                        </div>
                    </div>
                </div>
            </x-management.card>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 px-6 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    <i class="fi fi-rr-globe text-xs"></i> Create Storefront
                </button>
                <a href="{{ route('management.stores.show', $store) }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
            </div>
        </form>
    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">
        <x-management.card header="What You Get">
            <div class="space-y-4 text-sm">
                <div class="flex gap-3">
                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 mt-0.5" style="font-size:10px;">&#10003;</span>
                    <span class="text-slate-600">A branded online store at <strong>{{ $store->name ? Str::slug($store->name) : 'your-store' }}.{{ config('app.main_domain', 'storify.ng') }}</strong></span>
                </div>
                <div class="flex gap-3">
                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 mt-0.5" style="font-size:10px;">&#10003;</span>
                    <span class="text-slate-600">Product catalog with search and category filters</span>
                </div>
                <div class="flex gap-3">
                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 mt-0.5" style="font-size:10px;">&#10003;</span>
                    <span class="text-slate-600">Mobile-optimized shopping experience</span>
                </div>
                <div class="flex gap-3">
                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 mt-0.5" style="font-size:10px;">&#10003;</span>
                    <span class="text-slate-600">SEO-friendly URLs and social sharing</span>
                </div>
                <div class="flex gap-3">
                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0 mt-0.5" style="font-size:10px;">&#10003;</span>
                    <span class="text-slate-600">Integrated with your inventory — products sync automatically</span>
                </div>
            </div>
        </x-management.card>
    </div>
</div>
@endsection

@push('scripts')
<script>
let slugTimer = null;

document.querySelectorAll('.template-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.template-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;
    });
});

function toggleNationwide() {
    document.getElementById('nationwideFields').classList.toggle('hidden', !document.querySelector('input[name="is_nationwide"]').checked);
}

const nameInput = document.querySelector('input[name="store_name"]');
if (nameInput) {
    nameInput.addEventListener('input', function() {
        const name = this.value.trim();
        if (name.length < 2) {
            document.getElementById('slugUrl').textContent = 'Enter a store name above';
            document.getElementById('slugStatus').textContent = '';
            document.getElementById('slugInput').value = '';
            return;
        }
        clearTimeout(slugTimer);
        document.getElementById('slugStatus').textContent = '...';
        slugTimer = setTimeout(() => {
            fetch('/management/stores/check-slug', {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body: JSON.stringify({name})
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('slugUrl').textContent = data.url;
                document.getElementById('slugInput').value = data.slug || '';
                document.getElementById('slugStatus').innerHTML = data.available
                    ? '<span class="text-emerald-600 font-medium">✓</span>'
                    : '<span class="text-amber-600 font-medium">' + (data.slug || '') + '</span>';
            })
            .catch(() => { document.getElementById('slugStatus').textContent = ''; });
        }, 500);
    });
    // Trigger initial
    if (nameInput.value.trim().length >= 2) nameInput.dispatchEvent(new Event('input'));
}
</script>
@endpush
