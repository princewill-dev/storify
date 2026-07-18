@extends('admin.layout')
@section('subtitle', 'Stores')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Stores</h2>
    <div class="flex items-center gap-2">
        <button onclick="openModal('filterStoresModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            <i class="fi fi-rr-filter text-sm"></i> Filter
        </button>
        <button onclick="openModal('createStoreModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            <i class="fi fi-rr-plus text-sm"></i> Add Store
        </button>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">S/N</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Business</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Owner</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Shop Link</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($stores as $store)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-4 py-3 text-slate-400">{{ $loop->iteration }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                @if($store->logo_path)
                                    <img src="{{ asset('storage/'.$store->logo_path) }}" alt="" class="w-7 h-7 rounded object-cover border border-slate-200">
                                @endif
                                <span class="font-medium text-slate-900">{{ $store->name }}</span>
                                @if(isset($mainStoreId) && (int)$mainStoreId === (int)$store->id)
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-600">Main</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if($store->business)
                                <a href="{{ route('admin.vendors.show', $store->user) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $store->business->name }}</a>
                                <div class="text-xs text-slate-400 font-mono">{{ $store->business->business_code }}</div>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $store->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $store->businessType?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php($storeBadge = $storeStatusBadgeData[strtolower($store->status)] ?? null)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ ($storeBadge['class'] ?? '') ?: 'bg-slate-100 text-slate-600' }}">
                                {{ $storeBadge['label'] ?? ucfirst($store->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if(!empty($store->slug))
                                <a href="{{ route('home.store.products.index', ['store_subdomain' => $store->slug]) }}" target="_blank" class="text-xs text-indigo-600 hover:underline">view shop</a>
                            @else
                                <span class="text-slate-400 text-xs">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right" x-data="{ open: false }">
                            <div class="relative inline-block">
                                <button @click="open = !open" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                                    <i class="fi fi-rr-menu-dots text-sm"></i>
                                </button>
                                <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-44 bg-white rounded-lg shadow-lg border border-slate-200 py-1">
                                    <a href="{{ route('admin.stores.show', $store) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                        <i class="fi fi-rr-eye text-slate-400"></i> View
                                    </a>
                                    <button onclick="editStore(
                                        '{{ route('admin.stores.update', $store) }}',
                                        '{{ $store->business_id }}',
                                        '{{ addslashes($store->name) }}',
                                        '{{ $store->slug }}',
                                        '{{ addslashes($store->description) }}',
                                        '{{ $store->support_email }}',
                                        '{{ $store->support_phone }}',
                                        '{{ addslashes($store->address) }}',
                                        '{{ $store->instagram_url }}',
                                        '{{ $store->facebook_url }}',
                                        '{{ $store->twitter_url }}',
                                        '{{ $store->tiktok_url }}',
                                        '{{ $store->ownership_type_id }}',
                                        '{{ $store->business_type_id }}',
                                        '{{ $store->status }}',
                                        '{{ $store->logo_path ? asset('storage/'.$store->logo_path) : '' }}'
                                    ); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                        <i class="fi fi-rr-pencil text-slate-400"></i> Edit
                                    </button>
                                    @if(strtolower($store->status) === 'suspended' || strtolower($store->status) === 'inactive' || strtolower($store->status) === 'pending')
                                        <button onclick="storeAction('activateStoreForm', '{{ route('admin.stores.activate', $store) }}', '{{ addslashes($store->name) }}', 'activateStoreName', 'activateStoreModal'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                            <i class="fi fi-rr-check-circle text-slate-400"></i> Activate
                                        </button>
                                    @else
                                        <button onclick="storeAction('suspendStoreForm', '{{ route('admin.stores.suspend', $store) }}', '{{ addslashes($store->name) }}', 'suspendStoreName', 'suspendStoreModal'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left">
                                            <i class="fi fi-rr-ban text-slate-400"></i> Suspend
                                        </button>
                                    @endif
                                    <button onclick="storeAction('deleteStoreForm', '{{ route('admin.stores.destroy', $store) }}', '{{ addslashes($store->name) }}', 'deleteStoreName', 'deleteStoreModal'); open = false" class="flex items-center gap-2 w-full px-3 py-2 text-sm text-red-600 hover:bg-red-50 text-left">
                                        <i class="fi fi-rr-trash text-red-400"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-slate-400">No stores yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    <div class="px-4 py-3 border-t border-slate-100">
        {{ $stores->links() }}
    </div>
</div>

{{-- Create Store Modal --}}
<div id="createStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 max-h-[85vh] overflow-y-auto">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Add Store</h5>
            <form action="{{ route('admin.stores.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-y-3 items-start">
                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business</label>
                    <div class="sm:col-span-2">
                        <select name="business_id" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                            <option value="">Select a business...</option>
                            @foreach(($businesses ?? []) as $b)
                                <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Name</label>
                    <div class="sm:col-span-2"><input type="text" name="name" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Slug</label>
                    <div class="sm:col-span-2"><input type="text" name="slug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="auto-generated from name if left blank"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Description</label>
                    <div class="sm:col-span-2"><textarea name="description" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3" placeholder="Short description shown in listings"></textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Logo</label>
                    <div class="sm:col-span-2">
                        <div class="flex items-center gap-4">
                            <div class="w-40 h-20 rounded-lg border border-slate-200 flex items-center justify-center overflow-hidden bg-slate-50">
                                <img id="createStoreLogoPreview" class="max-w-full max-h-full object-contain" alt="">
                            </div>
                            <div>
                                <input type="file" name="logo" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".png,.jpg,.jpeg,.webp" onchange="previewImage(event, 'createStoreLogoPreview')">
                                <p class="text-xs text-slate-400 mt-1">PNG, JPG, WEBP. Max 2MB.</p>
                            </div>
                        </div>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Email</label>
                    <div class="sm:col-span-2"><input type="email" name="support_email" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Phone</label>
                    <div class="sm:col-span-2"><input type="text" name="support_phone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Address</label>
                    <div class="sm:col-span-2"><textarea name="address" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3"></textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Instagram URL</label>
                    <div class="sm:col-span-2"><input type="url" name="instagram_url" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://instagram.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Facebook URL</label>
                    <div class="sm:col-span-2"><input type="url" name="facebook_url" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://facebook.com/yourpage"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Twitter URL</label>
                    <div class="sm:col-span-2"><input type="url" name="twitter_url" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://twitter.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">TikTok URL</label>
                    <div class="sm:col-span-2"><input type="url" name="tiktok_url" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://www.tiktok.com/@yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Ownership Type</label>
                    <div class="sm:col-span-2">
                        <select name="ownership_type_id" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($ownershipTypes ?? []) as $o)
                                <option value="{{ $o->id }}">{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business Type</label>
                    <div class="sm:col-span-2">
                        <select name="business_type_id" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($businessTypes ?? []) as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Status</label>
                    <div class="sm:col-span-2">
                        <select name="status" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="active">active</option>
                            <option value="inactive">inactive</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeModal('createStoreModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Store Modal --}}
<div id="editStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-2xl p-6 max-h-[85vh] overflow-y-auto">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Edit Store</h5>
            <form id="editStoreForm" action="#" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-y-3 items-start">
                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business</label>
                    <div class="sm:col-span-2">
                        <select name="business_id" id="editStoreBusiness" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                            @foreach(($businesses ?? []) as $b)
                                <option value="{{ $b->id }}" {{ $store->business_id == $b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->owner?->name ?? 'No owner' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Name</label>
                    <div class="sm:col-span-2"><input type="text" name="name" id="editStoreName" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Slug</label>
                    <div class="sm:col-span-2"><input type="text" name="slug" id="editStoreSlug" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="auto-generated from name if left blank"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Description</label>
                    <div class="sm:col-span-2"><textarea name="description" id="editStoreDescription" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3" placeholder="Short description shown in listings"></textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Logo</label>
                    <div class="sm:col-span-2">
                        <div class="flex items-center gap-4">
                            <div class="w-40 h-20 rounded-lg border border-slate-200 flex items-center justify-center overflow-hidden bg-slate-50">
                                <img id="editStoreLogoPreview" class="max-w-full max-h-full object-contain" alt="">
                            </div>
                            <div>
                                <input type="file" name="logo" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" accept=".png,.jpg,.jpeg,.webp" onchange="previewImage(event, 'editStoreLogoPreview')">
                                <p class="text-xs text-slate-400 mt-1">PNG, JPG, WEBP. Max 2MB.</p>
                            </div>
                        </div>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Email</label>
                    <div class="sm:col-span-2"><input type="email" name="support_email" id="editStoreSupportEmail" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Support Phone</label>
                    <div class="sm:col-span-2"><input type="text" name="support_phone" id="editStoreSupportPhone" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Address</label>
                    <div class="sm:col-span-2"><textarea name="address" id="editStoreAddress" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3"></textarea></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Instagram URL</label>
                    <div class="sm:col-span-2"><input type="url" name="instagram_url" id="editStoreInstagramUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://instagram.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Facebook URL</label>
                    <div class="sm:col-span-2"><input type="url" name="facebook_url" id="editStoreFacebookUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://facebook.com/yourpage"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Twitter URL</label>
                    <div class="sm:col-span-2"><input type="url" name="twitter_url" id="editStoreTwitterUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://twitter.com/yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">TikTok URL</label>
                    <div class="sm:col-span-2"><input type="url" name="tiktok_url" id="editStoreTiktokUrl" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://www.tiktok.com/@yourhandle"></div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Ownership Type</label>
                    <div class="sm:col-span-2">
                        <select name="ownership_type_id" id="editStoreOwnershipType" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($ownershipTypes ?? []) as $o)
                                <option value="{{ $o->id }}">{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Business Type</label>
                    <div class="sm:col-span-2">
                        <select name="business_type_id" id="editStoreBusinessType" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Select...</option>
                            @foreach(($businessTypes ?? []) as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="text-sm font-medium text-slate-700 pt-1.5">Status</label>
                    <div class="sm:col-span-2">
                        <select name="status" id="editStoreStatus" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="active">active</option>
                            <option value="inactive">inactive</option>
                            <option value="suspended">suspended</option>
                            <option value="deleted">deleted</option>
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

{{-- Delete Store Modal --}}
<div id="deleteStoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteStoreModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
            <h5 class="text-base font-semibold text-red-600 mb-4">Delete Store</h5>
            <form id="deleteStoreForm" method="POST" class="space-y-4">
                @csrf
                @method('DELETE')
                <div class="px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-700">
                    This action will mark the store as deleted. The store will no longer be accessible.
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
                    <input type="text" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg bg-slate-50 text-slate-500" id="deleteStoreName" disabled>
                </div>
                <p class="text-xs text-slate-400">Note: Deletion will only proceed if all orders and transactions associated with this store are completed.</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeModal('deleteStoreModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete Store</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Filter Stores Modal --}}
<div id="filterStoresModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('filterStoresModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-lg p-6">
            <h5 class="text-base font-semibold text-slate-900 mb-4">Filter Stores</h5>
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">All</option>
                            <option value="active" @selected(($status ?? '')==='active')>Active</option>
                            <option value="inactive" @selected(($status ?? '')==='inactive')>Inactive</option>
                            <option value="suspended" @selected(($status ?? '')==='suspended')>Suspended</option>
                            <option value="deleted" @selected(($status ?? '')==='deleted')>Deleted</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Search</label>
                        <input type="text" name="q" value="{{ $q ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Name, store ID or vendor name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">From</label>
                        <input type="date" name="from" value="{{ $from ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">To</label>
                        <input type="date" name="to" value="{{ $to ?? '' }}" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('admin.stores.index') }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Reset</a>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewImage(event, previewId) {
    const f = event.target.files[0];
    if (!f) return;
    const r = new FileReader();
    r.onload = function(ev) {
        const img = document.getElementById(previewId);
        if (img) img.src = ev.target.result;
    };
    r.readAsDataURL(f);
}

function storeAction(formId, action, name, inputId, modalId) {
    const form = document.getElementById(formId);
    const input = document.getElementById(inputId);
    if (form) form.action = action;
    if (input) input.value = name || '';
    openModal(modalId);
}

function editStore(action, businessId, name, slug, description, supportEmail, supportPhone, address, instagramUrl, facebookUrl, twitterUrl, tiktokUrl, ownershipTypeId, businessTypeId, status, logoUrl) {
    const form = document.getElementById('editStoreForm');
    if (form) form.action = action;

    document.getElementById('editStoreBusiness').value = businessId || '';
    document.getElementById('editStoreName').value = name || '';
    document.getElementById('editStoreSlug').value = slug || '';
    document.getElementById('editStoreDescription').value = description || '';
    document.getElementById('editStoreSupportEmail').value = supportEmail || '';
    document.getElementById('editStoreSupportPhone').value = supportPhone || '';
    document.getElementById('editStoreAddress').value = address || '';
    document.getElementById('editStoreInstagramUrl').value = instagramUrl || '';
    document.getElementById('editStoreFacebookUrl').value = facebookUrl || '';
    document.getElementById('editStoreTwitterUrl').value = twitterUrl || '';
    document.getElementById('editStoreTiktokUrl').value = tiktokUrl || '';
    document.getElementById('editStoreOwnershipType').value = ownershipTypeId || '';
    document.getElementById('editStoreBusinessType').value = businessTypeId || '';
    const statusSelect = document.getElementById('editStoreStatus');
    if (statusSelect) {
        Array.from(statusSelect.options).forEach(function(opt) {
            opt.selected = (opt.value.toLowerCase() === (status || '').toLowerCase());
        });
    }
    const logoPreview = document.getElementById('editStoreLogoPreview');
    if (logoPreview) logoPreview.src = logoUrl || '';
    openModal('editStoreModal');
}
</script>
@endsection
