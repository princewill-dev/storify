@extends('admin.layout')
@section('subtitle', $store->name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Store: {{ $store->name }}</h2>
    <div class="flex flex-wrap items-center gap-2">
        <button onclick="openModal('editStoreModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-pencil text-sm"></i> Edit Store
        </button>
        @if(strtolower($store->status) === 'suspended')
            <button onclick="storeActionShow('activateStoreForm', '{{ route('admin.stores.activate', $store) }}', '{{ addslashes($store->name) }}', 'activateStoreName', 'activateStoreModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                Activate
            </button>
        @else
            <button onclick="storeActionShow('suspendStoreForm', '{{ route('admin.stores.suspend', $store) }}', '{{ addslashes($store->name) }}', 'suspendStoreName', 'suspendStoreModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600">
                Suspend
            </button>
        @endif
        <a href="{{ route('admin.stores.product.create', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Add Product</a>
        <a href="{{ route('admin.stores.categories.create', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Add Category</a>
        <a href="{{ route('admin.stores.products.index', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Products</a>
        <a href="{{ route('admin.stores.categories.index', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Categories</a>
        <a href="{{ route('admin.storefront-slides.index', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100">Edit Slides</a>
    </div>
</div>

{{-- Metric cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="text-xs text-slate-500">Total amount earned</div>
        <div class="text-xl font-bold text-slate-900 mt-1">₦0.00</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="text-xs text-slate-500">Customers</div>
        <div class="text-xl font-bold text-slate-900 mt-1">0</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="text-xs text-slate-500">Products</div>
        <div class="text-xl font-bold text-slate-900 mt-1">{{ $productCount }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="text-xs text-slate-500">Sales</div>
        <div class="text-xl font-bold text-slate-900 mt-1">0</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    {{-- Store Info --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Store Info</h3>
        </div>
        <div class="px-5 py-4 space-y-4">
            <div class="flex items-center gap-3">
                @if($store->logo_path)
                    <img src="{{ asset('storage/'.$store->logo_path) }}" alt="" class="w-14 h-14 rounded-lg object-contain border border-slate-200">
                @endif
                <div>
                    <div class="font-semibold text-slate-900">{{ $store->name }}</div>
                    <div class="text-xs text-slate-400">ID: <code class="text-slate-500">{{ $store->store_id }}</code> <span class="mx-1">·</span> Status: <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ $store->status }}</span></div>
                </div>
            </div>
            <div>
                <div class="text-xs text-slate-500 mb-1">Description</div>
                <p class="text-sm text-slate-700">{{ $store->description ?? '—' }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <div class="text-xs text-slate-500 mb-0.5">Support Email</div>
                    <div class="text-sm text-slate-700">{{ $store->support_email ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 mb-0.5">Support Phone</div>
                    <div class="text-sm text-slate-700">{{ $store->support_phone ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 mb-0.5">Ownership</div>
                    <div class="text-sm text-slate-700">{{ $store->ownershipType?->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 mb-0.5">Type</div>
                    <div class="text-sm text-slate-700">{{ $store->businessType?->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 mb-0.5">Business</div>
                    <div class="text-sm">
                        @if($store->business)
                            <a href="{{ route('admin.vendors.show', $store->user) }}" class="text-indigo-600 hover:underline">{{ $store->business->name }}</a>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </div>
                </div>
            </div>
            <div>
                <div class="text-xs text-slate-500 mb-0.5">Address</div>
                <div class="text-sm text-slate-700">{{ $store->address ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500 mb-1.5">Social Links</div>
                <div class="flex flex-wrap gap-2">
                    @if($store->instagram_url)
                        <a href="{{ $store->instagram_url }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Instagram</a>
                    @endif
                    @if($store->facebook_url)
                        <a href="{{ $store->facebook_url }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Facebook</a>
                    @endif
                    @if($store->twitter_url)
                        <a href="{{ $store->twitter_url }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Twitter</a>
                    @endif
                    @if($store->tiktok_url)
                        <a href="{{ $store->tiktok_url }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">TikTok</a>
                    @endif
                    @if(!$store->instagram_url && !$store->facebook_url && !$store->twitter_url && !$store->tiktok_url)
                        <span class="text-xs text-slate-400">—</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Business & Owner --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Business & Owner</h3>
        </div>
        <div class="px-5 py-4">
            @if($store->business)
                <div class="font-semibold text-slate-900">{{ $store->business->name }}</div>
                <div class="text-xs text-slate-400 font-mono">{{ $store->business->business_code }}</div>
                <div class="border-t border-slate-100 my-3"></div>
                <div class="space-y-2">
                    <div>
                        <div class="text-xs text-slate-500">Owner</div>
                        <div class="text-sm text-slate-900">{{ $store->user?->name ?? '—' }}</div>
                    </div>
                    <div class="text-xs text-slate-500">Email: {{ $store->user?->email ?? '—' }}</div>
                    <div class="text-xs text-slate-500">Phone: {{ $store->user?->phone ?? '—' }}</div>
                </div>
            @elseif($store->user)
                <div class="font-semibold text-slate-900">{{ $store->user->name }}</div>
                <div class="text-xs text-slate-500">Email: {{ $store->user->email ?? '—' }}</div>
                <div class="text-xs text-slate-500">Phone: {{ $store->user->phone ?? '—' }}</div>
                <div class="mt-3 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-700">No Business record found.</div>
            @else
                <p class="text-sm text-slate-400">No business or vendor assigned.</p>
            @endif
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- Products --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Products <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ml-2">{{ $productCount }}</span></h3>
        </div>
        <div class="px-5 py-4">
            @if($recentProducts->isEmpty())
                <p class="text-sm text-slate-400">No products yet.</p>
            @else
                <div class="space-y-1">
                    @foreach($recentProducts as $p)
                        <div class="flex justify-between items-center py-1.5 border-b border-slate-50 last:border-0">
                            <span class="text-sm text-slate-700">{{ $p->name }}</span>
                            <a href="{{ route('admin.products.edit', $p) }}" class="text-xs text-indigo-600 hover:underline">Edit</a>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="mt-3">
                <a href="{{ route('admin.stores.products.index', $store) }}" class="text-xs text-indigo-600 hover:underline">View all products</a>
            </div>
        </div>
    </div>

    {{-- Categories --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Categories <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ml-2">{{ $categories->count() }}</span></h3>
        </div>
        <div class="px-5 py-4">
            @if($categories->isEmpty())
                <p class="text-sm text-slate-400">No categories yet.</p>
            @else
                <div class="space-y-1">
                    @foreach($categories as $c)
                        <div class="py-1.5 border-b border-slate-50 last:border-0 text-sm text-slate-700">{{ $c->name }}</div>
                    @endforeach
                </div>
            @endif
            <div class="mt-3">
                <a href="{{ route('admin.stores.categories.index', $store) }}" class="text-xs text-indigo-600 hover:underline">Manage categories</a>
            </div>
        </div>
    </div>

    {{-- Packs --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Packs <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ml-2">{{ $packs->count() }}</span></h3>
        </div>
        <div class="px-5 py-4">
            @if($packs->isEmpty())
                <p class="text-sm text-slate-400">No packs yet.</p>
            @else
                <div class="space-y-1">
                    @foreach($packs as $pkg)
                        <div class="py-1.5 border-b border-slate-50 last:border-0 text-sm text-slate-700 flex justify-between">
                            <span>{{ $pkg->name }}</span>
                            <span class="text-xs text-slate-400">{{ number_format($pkg->amount,2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Edit Store Modal --}}
<div id="editStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 max-h-[85vh] overflow-y-auto">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Edit Store</h5>
            <form id="editStoreForm" action="{{ route('admin.stores.update', $store) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="redirect_to" value="{{ route('admin.stores.show', $store) }}">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-y-3 items-start">
                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business</label>
                    <div class="sm:col-span-2">
                        <select name="business_id" id="editStoreBusiness" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                            @foreach(($businesses ?? []) as $b)
                                <option value="{{ $b->id }}" {{ (int)($store->business_id) === (int)($b->id) ? 'selected' : '' }}>{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Name</label>
                    <div class="sm:col-span-2"><input type="text" name="name" id="editStoreName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->name }}" required></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Slug</label>
                    <div class="sm:col-span-2"><input type="text" name="slug" id="editStoreSlug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->slug }}" placeholder="auto-generated from name if left blank"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Description</label>
                    <div class="sm:col-span-2"><textarea name="description" id="editStoreDescription" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3" placeholder="Short description shown in listings">{{ $store->description }}</textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Logo</label>
                    <div class="sm:col-span-2">
                        <div class="flex items-center gap-4">
                            <div class="w-40 h-20 rounded-lg border border-slate-200 flex items-center justify-center overflow-hidden bg-slate-50">
                                <img id="editStoreLogoPreview" class="max-w-full max-h-full object-contain" src="{{ $store->logo_path ? asset('storage/'.$store->logo_path) : '' }}" alt="">
                            </div>
                            <div>
                                <input type="file" name="logo" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".png,.jpg,.jpeg,.webp" onchange="previewImageShow(event)">
                                <p class="text-xs text-slate-400 mt-1">PNG, JPG, WEBP. Max 2MB.</p>
                            </div>
                        </div>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Email</label>
                    <div class="sm:col-span-2"><input type="email" name="support_email" id="editStoreSupportEmail" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->support_email }}"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Phone</label>
                    <div class="sm:col-span-2"><input type="text" name="support_phone" id="editStoreSupportPhone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->support_phone }}"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Address</label>
                    <div class="sm:col-span-2"><textarea name="address" id="editStoreAddress" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3">{{ $store->address }}</textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Instagram URL</label>
                    <div class="sm:col-span-2"><input type="url" name="instagram_url" id="editStoreInstagramUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->instagram_url }}" placeholder="https://instagram.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Facebook URL</label>
                    <div class="sm:col-span-2"><input type="url" name="facebook_url" id="editStoreFacebookUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->facebook_url }}" placeholder="https://facebook.com/yourpage"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Twitter URL</label>
                    <div class="sm:col-span-2"><input type="url" name="twitter_url" id="editStoreTwitterUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->twitter_url }}" placeholder="https://twitter.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">TikTok URL</label>
                    <div class="sm:col-span-2"><input type="url" name="tiktok_url" id="editStoreTiktokUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" value="{{ $store->tiktok_url }}" placeholder="https://www.tiktok.com/@yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Ownership Type</label>
                    <div class="sm:col-span-2">
                        <select name="ownership_type_id" id="editStoreOwnershipType" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($ownershipTypes ?? []) as $o)
                                <option value="{{ $o->id }}" {{ (int)($store->ownership_type_id) === (int)($o->id) ? 'selected' : '' }}>{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business Type</label>
                    <div class="sm:col-span-2">
                        <select name="business_type_id" id="editStoreBusinessType" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($businessTypes ?? []) as $b)
                                <option value="{{ $b->id }}" {{ (int)($store->business_type_id) === (int)($b->id) ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Status</label>
                    <div class="sm:col-span-2">
                        <select name="status" id="editStoreStatus" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="active" {{ $store->status==='active' ? 'selected' : '' }}>active</option>
                            <option value="inactive" {{ $store->status==='inactive' ? 'selected' : '' }}>inactive</option>
                            <option value="suspended" {{ $store->status==='suspended' ? 'selected' : '' }}>suspended</option>
                            <option value="deleted" {{ $store->status==='deleted' ? 'selected' : '' }}>deleted</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeModal('editStoreModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Suspend Store Modal --}}
<div id="suspendStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('suspendStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Suspend Store</h5>
            <form id="suspendStoreForm" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="suspendStoreName" disabled>
                </div>
                <div>
                    <label for="suspendStoreReason" class="block text-sm font-medium text-slate-700 mb-1">Reason</label>
                    <textarea class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" id="suspendStoreReason" name="reason" rows="4" placeholder="Provide reason for suspension" required></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('suspendStoreModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Suspend</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Activate Store Modal --}}
<div id="activateStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('activateStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Activate Store</h5>
            <form id="activateStoreForm" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="activateStoreName" disabled>
                </div>
                <div>
                    <label for="activateStoreReason" class="block text-sm font-medium text-slate-700 mb-1">Reason / Notes</label>
                    <textarea class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" id="activateStoreReason" name="reason" rows="4" placeholder="Provide reason for activation" required></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('activateStoreModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Activate</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function storeActionShow(formId, action, name, inputId, modalId) {
    const form = document.getElementById(formId);
    const input = document.getElementById(inputId);
    if (form) form.action = action;
    if (input) input.value = name || '';
    openModal(modalId);
}

function previewImageShow(event) {
    const f = event.target.files[0];
    if (!f) return;
    const r = new FileReader();
    r.onload = function(ev) {
        const img = document.getElementById('editStoreLogoPreview');
        if (img) img.src = ev.target.result;
    };
    r.readAsDataURL(f);
}
</script>
@endpush
