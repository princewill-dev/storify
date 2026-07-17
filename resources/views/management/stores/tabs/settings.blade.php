<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left Column --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Storefront CTA --}}
        @unless($store->has_website)
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-100 p-5 flex items-center gap-4">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-blue-600 text-white shrink-0">
                <i class="fi fi-rr-globe text-lg"></i>
            </span>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-slate-900">Enable Online Storefront</h3>
                <p class="text-xs text-slate-600 mt-0.5">Give this store an online presence and start selling to customers anywhere.</p>
            </div>
            <a href="{{ route('management.stores.storefront.create', $store) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors shrink-0">
                <i class="fi fi-rr-plus text-xs"></i> Create Storefront
            </a>
        </div>
        @endunless

        {{-- Store Details --}}
        <x-management.card header="Store Details">
            <form method="POST" action="{{ route('management.stores.update', $store) }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <input type="hidden" name="redirect_to" value="{{ route('management.stores.show', $store) }}">
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Store Name <span class="text-red-400">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $store->name) }}" required class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Support Email</label>
                            <input type="email" name="support_email" value="{{ old('support_email', $store->support_email) }}" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Support Phone</label>
                            <input type="text" name="support_phone" value="{{ old('support_phone', $store->support_phone) }}" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Address</label>
                            <input type="text" name="address" value="{{ old('address', $store->address) }}" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                        <textarea name="description" rows="3" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">{{ old('description', $store->description) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Store Logo</label>
                        <div class="flex items-center gap-4">
                            @if($store->logoUrl())
                            <img src="{{ $store->logoUrl() }}" alt="" class="h-14 w-14 rounded-xl object-cover border border-slate-200 bg-slate-50 shrink-0">
                            @endif
                            <input type="file" name="logo" accept="image/*" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-colors">
                            <i class="fi fi-rr-check text-xs"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </x-management.card>

        {{-- Social Links --}}
        <x-management.card header="Social Links">
            <form method="POST" action="{{ route('management.stores.update', $store) }}">
                @csrf @method('PUT')
                <input type="hidden" name="redirect_to" value="{{ route('management.stores.show', $store) }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5"><i class="fi fi-brands-instagram text-pink-500 mr-1.5"></i> Instagram</label>
                        <input type="url" name="instagram_url" value="{{ old('instagram_url', $store->instagram_url) }}" placeholder="https://instagram.com/..." class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5"><i class="fi fi-brands-facebook text-blue-600 mr-1.5"></i> Facebook</label>
                        <input type="url" name="facebook_url" value="{{ old('facebook_url', $store->facebook_url) }}" placeholder="https://facebook.com/..." class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5"><i class="fi fi-brands-twitter text-sky-500 mr-1.5"></i> X (Twitter)</label>
                        <input type="url" name="twitter_url" value="{{ old('twitter_url', $store->twitter_url) }}" placeholder="https://x.com/..." class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5"><i class="fi fi-brands-tik-tok text-slate-800 mr-1.5"></i> TikTok</label>
                        <input type="url" name="tiktok_url" value="{{ old('tiktok_url', $store->tiktok_url) }}" placeholder="https://tiktok.com/..." class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-colors">
                    <i class="fi fi-rr-check text-xs"></i> Save Social Links
                </button>
            </form>
        </x-management.card>

        {{-- Payment Methods --}}
        <x-management.card header="Payment Methods">
            @php $storeMethods = $store->paymentMethods()->wherePivot('is_active', true)->get(); @endphp
            @if($storeMethods->isEmpty())
            <div class="text-center py-6">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-credit-card"></i></span>
                <p class="text-sm text-slate-500 mb-2">No payment methods assigned</p>
                <a href="{{ route('management.payment-settings.index') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">Configure in Payment Settings →</a>
            </div>
            @else
            <div class="grid grid-cols-2 gap-3">
                @foreach($storeMethods as $method)
                <div class="flex items-center gap-3 rounded-lg border border-slate-200 p-3">
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg {{ $method->type === 'gateway' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }} shrink-0">
                        <i class="fi fi-rr-{{ $method->type === 'gateway' ? 'credit-card' : 'bank' }} text-sm"></i>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ $method->name }}</p>
                        <p class="text-xs text-slate-400">{{ $method->type === 'gateway' ? 'Card Payment' : 'Bank Transfer' }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </x-management.card>

        {{-- Assigned Staff --}}
        <x-management.card header="Assigned Staff">
            @if($store->assignedStaff->isNotEmpty())
            <div class="divide-y divide-slate-100 -mx-5 -mt-5 mb-5">
                @foreach($store->assignedStaff as $staffMember)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-500 shrink-0">
                            {{ strtoupper(substr($staffMember->name, 0, 2)) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-slate-800">{{ $staffMember->name }}</p>
                                @php $roles = $staffMember->getRoleNames(); @endphp
                                @if($roles->isNotEmpty())
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">{{ $roles->join(', ') }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-400">{{ $staffMember->email }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('management.stores.remove-staff', ['store' => $store, 'user' => $staffMember]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700 transition-colors">Remove</button>
                    </form>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-sm text-slate-400 mb-5">No staff assigned to this store yet.</p>
            @endif

            @if($availableStaff->isNotEmpty())
            <form method="POST" action="{{ route('management.stores.assign-staff', $store) }}" class="flex items-end gap-3 pt-4 border-t border-slate-100">
                @csrf
                <div class="flex-1">
                    <select name="user_id" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="">Select staff to assign...</option>
                        @foreach($availableStaff as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->roles->pluck('name')->join(', ') ?: 'No role' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-colors shrink-0">
                    <i class="fi fi-rr-plus text-xs"></i> Assign
                </button>
            </form>
            @endif
        </x-management.card>
    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">
        {{-- Store Info --}}
        <x-management.card header="Store Info">
            @if($store->logoUrl())
            <img src="{{ $store->logoUrl() }}" alt="" class="h-16 w-16 rounded-xl object-cover border border-slate-200 bg-slate-50 mb-4">
            @endif
            <div class="text-sm space-y-3">
                <div class="flex justify-between"><span class="text-slate-500">Store ID</span><span class="font-mono font-medium text-slate-700 text-xs">{{ $store->store_id }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Created</span><span class="font-medium text-slate-700 text-xs">{{ $store->created_at->format('d M, Y') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Business</span><span class="font-medium text-slate-700 text-xs">{{ $store->businessType?->name ?? '—' }}</span></div>
                <div class="flex justify-between items-center"><span class="text-slate-500">Status</span><x-management.status-badge :status="$store->status" /></div>
            </div>
        </x-management.card>

        {{-- POS Terminal --}}
        <x-management.card header="POS Terminal">
            @if($store->pos_enabled)
            <div class="text-center space-y-3">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Enabled
                </span>
                <a href="{{ route('management.pos.terminal', $store) }}" class="block w-full py-2.5 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">
                    <i class="fi fi-rr-terminal mr-1.5"></i> Open Terminal
                </a>
            </div>
            @else
            <div class="text-center py-2">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-3"><i class="fi fi-rr-terminal"></i></span>
                <p class="text-sm text-slate-500 mb-3">POS terminal is not enabled</p>
                <form method="POST" action="{{ route('management.pos.enable', $store) }}">
                    @csrf
                    <button type="submit" class="w-full py-2.5 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors">
                        <i class="fi fi-rr-power mr-1.5"></i> Enable POS
                    </button>
                </form>
            </div>
            @endif
        </x-management.card>

        {{-- Danger Zone --}}
        <x-management.card>
            <h4 class="text-sm font-semibold text-red-600 mb-3">Danger Zone</h4>
            @if($store->status === 'suspended')
            <form method="POST" action="{{ route('management.stores.activate', $store) }}">
                @csrf @method('PATCH')
                <button type="submit" class="w-full py-2.5 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors">
                    <i class="fi fi-rr-power mr-1.5"></i> Reactivate Store
                </button>
            </form>
            @else
            <form method="POST" action="{{ route('management.stores.suspend', $store) }}" x-data="{ showReason: false }">
                @csrf @method('PATCH')
                <div x-show="showReason" class="mb-3">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Suspension Reason</label>
                    <input type="text" name="reason" placeholder="Why are you suspending this store?" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500">
                </div>
                <button type="button" @click="showReason = !showReason" x-show="!showReason" class="w-full py-2.5 border border-red-200 text-red-600 text-xs font-semibold rounded-lg hover:bg-red-50 transition-colors">
                    <i class="fi fi-rr-pause mr-1.5"></i> Suspend Store
                </button>
                <button type="submit" x-show="showReason" class="w-full py-2.5 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors">
                    <i class="fi fi-rr-pause mr-1.5"></i> Confirm Suspend
                </button>
                <button type="button" @click="showReason = false" x-show="showReason" class="w-full py-2 text-xs text-slate-400 hover:text-slate-600 mt-1">Cancel</button>
            </form>
            @endif

            <hr class="my-4 border-red-100">

            <button onclick="document.getElementById('deleteStoreModal{{ $store->id }}').classList.remove('hidden')" class="w-full py-2.5 border border-red-200 text-red-600 text-xs font-semibold rounded-lg hover:bg-red-50 transition-colors">
                <i class="fi fi-rr-trash mr-1.5"></i> Delete Store
            </button>

            {{-- Delete Confirmation Modal --}}
            <div id="deleteStoreModal{{ $store->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('deleteStoreModal{{ $store->id }}').classList.add('hidden')"></div>
                    <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
                        <div class="px-6 py-4 border-b border-slate-100">
                            <h3 class="text-base font-semibold text-slate-800">Delete Store</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="text-center">
                                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-red-100 mb-3">
                                    <i class="fi fi-rr-trash text-xl text-red-500"></i>
                                </div>
                                <p class="text-sm text-slate-600">Are you sure you want to delete <strong>{{ $store->name }}</strong>?</p>
                                <p class="text-xs text-slate-400 mt-2">This action cannot be undone. The store and all its data will be hidden from your account. Orders, products, and settings will be preserved.</p>
                            </div>
                            <form method="POST" action="{{ route('management.stores.destroy', $store) }}">
                                @csrf @method('DELETE')
                                <div class="flex items-center gap-3">
                                    <button type="submit" class="flex-1 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Delete Store</button>
                                    <button type="button" onclick="document.getElementById('deleteStoreModal{{ $store->id }}').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </x-management.card>
    </div>
</div>
