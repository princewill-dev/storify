@extends('management.layout')
@section('subtitle', 'Edit Service')

@section('content')
<div class="flex items-center gap-3 mb-4">
    <a href="{{ route('management.services.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
        <i class="fi fi-rr-arrow-left text-xs"></i> Services
    </a>
    <div class="h-4 w-px bg-slate-200"></div>
    <span class="text-sm font-semibold text-slate-800">Edit Service</span>
    <span class="text-xs text-slate-400">{{ $service->service_code }}</span>
</div>

@if(session('success'))
<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 mb-4">{{ session('success') }}</div>
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

<form method="POST" action="{{ route('management.services.update', $service) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-800 mb-4">Service Details</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $service->name) }}" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 px-3.5 py-2.5" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Description</label>
                        <textarea name="description" rows="3" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 px-3.5 py-2.5">{{ old('description', $service->description) }}</textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Price</label>
                            <div class="flex">
                                <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 text-slate-500 text-sm">₦</span>
                                <input type="number" name="amount" value="{{ old('amount', $service->amount) }}" step="0.01" min="0" class="w-full rounded-r-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 px-3.5 py-2.5">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Currency</label>
                            <select name="currency_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5">
                                @foreach($currencies as $cur)
                                <option value="{{ $cur->id }}" @selected(old('currency_id', $service->currency_id) == $cur->id)>{{ $cur->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Existing Images --}}
                    @if($service->images->isNotEmpty())
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-2">Existing Images</label>
                        <div class="flex flex-wrap gap-3">
                            @foreach($service->images as $img)
                            <div class="w-32 border border-slate-200 rounded-lg p-2">
                                <img src="{{ asset('storage/' . $img->path) }}" alt="" class="w-full h-20 object-cover rounded mb-2">
                                <label class="flex items-center gap-1 text-[10px] text-slate-500">
                                    <input type="radio" name="primary_image_id" value="{{ $img->id }}" @checked($img->is_primary) class="rounded border-slate-300 text-indigo-600"> Primary
                                </label>
                                <label class="flex items-center gap-1 text-[10px] text-red-500 mt-0.5">
                                    <input type="checkbox" name="delete_image_ids[]" value="{{ $img->id }}" class="rounded border-slate-300 text-red-600"> Delete
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Add Images</label>
                        <div class="border-2 border-dashed border-slate-200 rounded-lg p-6 text-center hover:border-indigo-300 transition-colors cursor-pointer" x-data @click="$refs.images.click()">
                            <input type="file" name="images[]" x-ref="images" accept="image/*" multiple class="hidden" @change="$el.nextElementSibling.textContent = $el.files.length + ' file(s) selected'">
                            <i class="fi fi-rr-picture text-slate-300 text-2xl mb-1 block"></i>
                            <p class="text-sm text-slate-400">Upload more images</p>
                            <p class="text-xs text-slate-400 mt-0.5">JPG, PNG or GIF</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-800 mb-3">Store & Status</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Store</label>
                        <select name="store_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5">
                            @foreach($stores as $s)
                            <option value="{{ $s->id }}" @selected(old('store_id', $service->store_id) == $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5">
                            <option value="active" @selected(old('status', $service->status) == 'active')>Active</option>
                            <option value="inactive" @selected(old('status', $service->status) == 'inactive')>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="flex-1 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
                    <i class="fi fi-rr-check text-xs mr-1"></i> Save Changes
                </button>
                <a href="{{ route('management.services.index') }}" class="flex-1 py-2.5 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors text-center">Cancel</a>
            </div>
        </div>
    </div>
</form>
@endsection
