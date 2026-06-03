@extends('management.layout')
@section('subtitle', $store->name . ' — Settings')

@section('content')
<div class="flex items-center gap-3 mb-6">
    @if($store->logoUrl())
        <img src="{{ $store->logoUrl() }}" alt="{{ $store->name }}" class="h-8 w-8 rounded-lg object-cover shrink-0 bg-slate-100">
    @endif
    <h2 class="text-lg font-semibold text-slate-900">{{ $store->name }}</h2>
</div>

{{-- Tab Bar --}}
<div class="flex items-center gap-1 mb-6 border-b border-slate-200">
    <a href="{{ route('management.stores.show', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px transition-colors">Dashboard</a>
    @if($store->has_website)
    <a href="{{ route('management.stores.web-metrics', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px transition-colors">Web Metrics</a>
    @endif
    <a href="{{ route('management.stores.settings', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-slate-900 text-slate-900 -mb-px">Settings</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left --}}
    <div class="lg:col-span-2 space-y-6">

        <x-management.card header="Store Details">
            <form method="POST" action="{{ route('management.stores.update', $store) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-management.form-input name="name" label="Store Name" :value="old('name', $store->name)" required :error="$errors->first('name')" />
                    <x-management.form-input name="slug" label="Slug" :value="old('slug', $store->slug)" :error="$errors->first('slug')" />
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

        <x-management.card header="Staff Assignments">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($store->assignedStaff as $staff)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <img src="{{ $staff->photoUrl() }}" alt="" class="h-7 w-7 rounded-full object-cover shrink-0 bg-slate-200">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $staff->name }}</p>
                            <p class="text-xs text-slate-400 truncate">{{ $staff->getRoleNames()->implode(', ') ?: 'No role' }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('management.stores.remove-staff', ['store' => $store, 'user' => $staff]) }}" class="shrink-0 ml-3">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-500 hover:text-red-700 font-medium">Remove</button>
                    </form>
                </div>
                @empty
                <div class="px-5 py-4 text-center text-sm text-slate-400">No staff assigned</div>
                @endforelse
            </div>
            @if($availableStaff->isNotEmpty())
            <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 -mx-5 -mb-5">
                <form method="POST" action="{{ route('management.stores.assign-staff', $store) }}" class="flex items-center gap-2">
                    @csrf
                    <select name="user_id" class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                        <option value="">Add staff member...</option>
                        @foreach($availableStaff as $s)
                        <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->email }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="shrink-0 inline-flex items-center gap-1 px-3 py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors">Assign</button>
                </form>
            </div>
            @endif
        </x-management.card>

        <x-management.card header="Bank Accounts">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($store->banks as $bank)
                <div class="px-5 py-3"><p class="text-sm font-medium text-slate-800">{{ $bank->bank_name }}</p><p class="text-xs text-slate-400">{{ $bank->account_number }} · {{ $bank->account_name }}</p></div>
                @empty
                <div class="px-5 py-4 text-center text-sm text-slate-400">No bank accounts</div>
                @endforelse
            </div>
        </x-management.card>

        <x-management.card header="Delivery Routes">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($store->deliveryRoutes as $route)
                <div class="px-5 py-3"><p class="text-sm font-medium text-slate-800">{{ $route->area ?? $route->state }}, {{ $route->country }}</p><p class="text-xs text-slate-400">₦{{ number_format($route->fee, 2) }} · {{ $route->delivery_days }} days</p></div>
                @empty
                <div class="px-5 py-4 text-center text-sm text-slate-400">No delivery routes</div>
                @endforelse
            </div>
        </x-management.card>

    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">

        <x-management.card header="Store Info">
            @if($store->logoUrl())
            <div class="flex justify-center mb-3">
                <img src="{{ $store->logoUrl() }}" alt="{{ $store->name }}" class="h-20 w-20 rounded-xl object-cover bg-slate-100">
            </div>
            @endif
            <div class="space-y-3">
                <div class="flex justify-between"><span class="text-sm text-slate-500">Store ID</span><span class="text-sm font-medium text-slate-700">{{ $store->store_id }}</span></div>
                <div class="flex justify-between"><span class="text-sm text-slate-500">Created</span><span class="text-sm font-medium text-slate-700">{{ $store->created_at->format('d M Y') }}</span></div>
                <div class="flex justify-between"><span class="text-sm text-slate-500">Business</span><span class="text-sm font-medium text-slate-700">{{ $store->business->name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-sm text-slate-500">Status</span><span><x-management.status-badge :status="$store->status" /></span></div>
            </div>
        </x-management.card>

        <x-management.card header="POS Configuration">
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-slate-500">POS Terminal</span>
                    <span class="text-sm font-semibold {{ $store->pos_enabled ? 'text-emerald-600' : 'text-slate-400' }}">{{ $store->pos_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                @if($store->pos_enabled)
                <form method="POST" action="{{ route('management.pos.open', $store) }}" class="space-y-2">
                    @csrf
                    <input type="number" name="opening_balance" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="Opening cash float (kobo)" required>
                    <button type="submit" class="w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors">Open Session</button>
                </form>
                @else
                <form method="POST" action="{{ route('management.pos.enable', $store) }}">
                    @csrf
                    <button type="submit" class="w-full py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors">Enable POS</button>
                </form>
                @endif
            </div>
        </x-management.card>

        <x-management.card>
            <div class="space-y-2">
                <a href="{{ route('management.pos.sessions.index', $store) }}" class="block w-full py-2 bg-white border border-slate-200 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-50 transition-colors text-center">POS Session History</a>

                @if($store->status === 'active')
                <form method="POST" action="{{ route('management.stores.suspend', $store) }}" onsubmit="return confirm('Suspend this store? It will be hidden from customers.')">
                    @csrf @method('PATCH')
                    <input type="hidden" name="reason" value="Suspended via settings">
                    <button type="submit" class="w-full py-2 bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold rounded-lg hover:bg-amber-100 transition-colors">Suspend Store</button>
                </form>
                @elseif($store->status === 'suspended')
                <form method="POST" action="{{ route('management.stores.activate', $store) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="reason" value="Reactivated via settings">
                    <button type="submit" class="w-full py-2 bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold rounded-lg hover:bg-emerald-100 transition-colors">Reactivate Store</button>
                </form>
                @endif

                @if($store->status !== 'deleted')
                <form method="POST" action="{{ route('management.stores.destroy', $store) }}" onsubmit="return confirm('Delete this store? This cannot be undone. All orders and transactions must be completed first.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="w-full py-2 bg-red-50 border border-red-200 text-red-700 text-xs font-semibold rounded-lg hover:bg-red-100 transition-colors"><i class="fi fi-rr-trash mr-1 text-xs"></i> Delete Store</button>
                </form>
                @endif
            </div>
        </x-management.card>

    </div>
</div>
@endsection
