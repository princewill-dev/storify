<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- Left Column --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Storefront CTA (only for stores without website) --}}
        @unless($store->has_website)
        <x-management.card>
            <div class="flex items-center gap-4">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-blue-50 text-blue-600 shrink-0">
                    <i class="fi fi-rr-globe text-lg"></i>
                </span>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-slate-800">Enable Online Storefront</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Give this store an online presence. Sell to customers anywhere in Nigeria.</p>
                </div>
                <a href="{{ route('management.stores.storefront.create', $store) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors shrink-0">
                    <i class="fi fi-rr-plus text-xs"></i> Create Storefront
                </a>
            </div>
        </x-management.card>
        @endunless

        {{-- Store Details --}}
        <x-management.card header="Store Details">
            <form method="POST" action="{{ route('management.stores.update', $store) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="redirect_to" value="{{ route('management.stores.show', $store) }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="name" label="Store Name" :value="old('name', $store->name)" required :error="$errors->first('name')" />
                    <x-management.form-input name="support_email" label="Support Email" type="email" :value="old('support_email', $store->support_email)" :error="$errors->first('support_email')" />
                    <x-management.form-input name="support_phone" label="Support Phone" :value="old('support_phone', $store->support_phone)" :error="$errors->first('support_phone')" />
                </div>
                <x-management.form-input name="address" label="Address" :value="old('address', $store->address)" />
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                    <textarea name="description" rows="3" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">{{ old('description', $store->description) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Store Logo</label>
                    @if($store->logoUrl())
                        <img src="{{ $store->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-cover mb-2 bg-slate-100">
                    @endif
                    <input type="file" name="logo" accept="image/*" class="block w-full text-sm text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                </div>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
            </form>
        </x-management.card>

        {{-- Social Links --}}
        <x-management.card header="Social Links">
            <form method="POST" action="{{ route('management.stores.update', $store) }}" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="redirect_to" value="{{ route('management.stores.show', $store) }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="instagram_url" label="Instagram" type="url" :value="old('instagram_url', $store->instagram_url)" placeholder="https://instagram.com/..." />
                    <x-management.form-input name="facebook_url" label="Facebook" type="url" :value="old('facebook_url', $store->facebook_url)" placeholder="https://facebook.com/..." />
                    <x-management.form-input name="twitter_url" label="X (Twitter)" type="url" :value="old('twitter_url', $store->twitter_url)" placeholder="https://x.com/..." />
                    <x-management.form-input name="tiktok_url" label="TikTok" type="url" :value="old('tiktok_url', $store->tiktok_url)" placeholder="https://tiktok.com/..." />
                </div>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Social Links</button>
            </form>
        </x-management.card>

        {{-- Store Payment Methods --}}
        <x-management.card header="Payment Methods">
            @php
                $storeBanks = $store->banks;
                $storePaystack = $store->paymentGateways->where('gateway', 'paystack')->first();
            @endphp
            <div class="space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Bank Accounts</h4>
                    </div>
                    @if($storeBanks->isEmpty())
                    <p class="text-xs text-slate-400 py-1">No bank accounts. Add bank accounts from <a href="{{ route('management.payment-settings.index') }}" class="text-blue-600 hover:underline">Payment Settings</a>.</p>
                    @else
                    <div class="space-y-2">
                        @foreach($storeBanks as $bank)
                        <div class="flex items-center justify-between text-sm">
                            <div>
                                <span class="font-medium text-slate-700">{{ $bank->bank_name }}</span>
                                <span class="text-slate-400 ml-1.5">{{ $bank->account_number }}</span>
                                @if($bank->is_primary)<span class="inline-flex items-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 ml-1.5">Primary</span>@endif
                            </div>
                            <span class="text-xs text-slate-400">{{ $bank->account_name }}</span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                <div class="pt-3 border-t border-slate-100">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Paystack</h4>
                    </div>
                    @if($storePaystack && $storePaystack->is_active)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span class="text-slate-600">Connected</span>
                        <code class="text-xs text-slate-400">{{ $storePaystack->masked_public_key }}</code>
                    </div>
                    @else
                    <p class="text-xs text-slate-400">Using business-wide Paystack gateway from <a href="{{ route('management.payment-settings.index') }}" class="text-blue-600 hover:underline">Payment Settings</a>.</p>
                    @endif
                </div>
            </div>
        </x-management.card>

        {{-- Staff Assignments --}}
        <x-management.card id="assigned-staff-card" header="Assigned Staff">
            @if($store->assignedStaff->isNotEmpty())
            <div class="divide-y divide-slate-100 -mx-5 -mt-5 mb-4">
                @foreach($store->assignedStaff as $staffMember)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 text-xs font-bold shrink-0">
                            {{ strtoupper(substr($staffMember->name, 0, 2)) }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-800">{{ $staffMember->name }}</p>
                            <p class="text-xs text-slate-400">{{ $staffMember->email }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('management.stores.remove-staff', ['store' => $store, 'user' => $staffMember]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">Remove</button>
                    </form>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-sm text-slate-400 mb-4">No staff assigned to this store yet.</p>
            @endif

            @if($availableStaff->isNotEmpty())
            <form method="POST" action="{{ route('management.stores.assign-staff', $store) }}" class="flex items-end gap-3 border-t border-slate-100 pt-4">
                @csrf
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Assign Staff</label>
                    <select name="user_id" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                        <option value="">Select staff...</option>
                        @foreach($availableStaff as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->roles->pluck('name')->join(', ') ?: 'No role' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors shrink-0">Assign</button>
            </form>
            @endif
        </x-management.card>
    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">
        {{-- Store Info --}}
        <x-management.card header="Store Info">
            <div class="text-sm space-y-3">
                @if($store->logoUrl())
                <img src="{{ $store->logoUrl() }}" alt="" class="h-16 w-16 rounded-xl object-cover bg-slate-100">
                @endif
                <div class="flex justify-between"><span class="text-slate-500">Store ID</span><span class="font-mono font-medium text-slate-700">{{ $store->store_id }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Created</span><span class="font-medium text-slate-700">{{ $store->created_at->format('d M, Y') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Business</span><span class="font-medium text-slate-700">{{ $store->businessType?->name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Status</span><x-management.status-badge :status="$store->status" /></div>
            </div>
        </x-management.card>

        {{-- POS Card --}}
        <x-management.card header="POS Terminal">
            @if($store->pos_enabled)
            <div class="text-center space-y-3">
                <div class="flex items-center gap-2 justify-center">
                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span class="text-sm font-medium text-emerald-700">Enabled</span>
                </div>
                <a href="{{ route('management.pos.terminal', $store) }}" class="block w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">Open Terminal</a>
            </div>
            @else
            <div class="text-center py-2">
                <p class="text-sm text-slate-500 mb-3">POS not enabled</p>
                <form method="POST" action="{{ route('management.pos.enable', $store) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors">Enable POS</button>
                </form>
            </div>
            @endif
        </x-management.card>

        {{-- Danger Zone --}}
        <x-management.card header="Danger Zone">
            <div class="space-y-3">
                @if($store->status === 'suspended')
                <form method="POST" action="{{ route('management.stores.activate', $store) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="w-full py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors">Reactivate Store</button>
                </form>
                @else
                <form method="POST" action="{{ route('management.stores.suspend', $store) }}" x-data="{ showReason: false }">
                    @csrf @method('PATCH')
                    <div x-show="showReason" class="mb-2">
                        <input type="text" name="reason" placeholder="Reason for suspension..." class="block w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-red-500 focus:ring-red-500">
                    </div>
                    <button type="button" @click="showReason = !showReason" x-show="!showReason" class="w-full py-2 bg-amber-600 text-white text-xs font-semibold rounded-lg hover:bg-amber-700 transition-colors">Suspend Store</button>
                    <button type="submit" x-show="showReason" class="w-full py-2 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors">Confirm Suspend</button>
                </form>
                @endif
            </div>
        </x-management.card>
    </div>
</div>
