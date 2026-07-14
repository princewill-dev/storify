{{-- Mobile overlay --}}
<div x-show="mobileMenuOpen" @click="mobileMenuOpen = false" x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

@php
$authUser = Auth::user();
$activeSidebarGroup = null;
$activeSidebarStoreId = request()->route('store')?->id;
$activeSidebarWarehouseId = request()->route('warehouse')?->id;

if (request()->routeIs('management.stores.*') || request()->routeIs('management.orders.*') || (request()->routeIs('management.staff.*') && request()->filled('store_id'))) {
    $activeSidebarGroup = 'stores';
    if (request()->filled('store_id') && !$activeSidebarStoreId) {
        $resolvedStore = ($sidebarStores ?? collect())->where('store_id', request()->query('store_id'))->first();
        $activeSidebarStoreId = $resolvedStore?->id;
    }
} elseif (request()->routeIs('management.products.*') || request()->routeIs('management.categories.*') || request()->routeIs('management.services.*') || request()->routeIs('management.stores.products')) {
    $activeSidebarGroup = 'products';
} elseif (request()->routeIs('management.warehouses.*') || request()->routeIs('management.sections.*')) {
    $activeSidebarGroup = 'warehouses';
} elseif (request()->routeIs('management.pos.*')) {
    $activeSidebarGroup = 'pos';
}
@endphp

<aside 
    class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-slate-900 transition-transform duration-200 lg:translate-x-0"
    :class="{ '-translate-x-full': !mobileMenuOpen && !sidebarOpen, 'translate-x-0': mobileMenuOpen || sidebarOpen }"
    @click.outside="mobileMenuOpen = false"
>

    {{-- Logo --}}
    <div class="flex h-16 items-center gap-3 px-5 border-b border-slate-800">
        <img src="{{ $company->favicon }}" alt="" class="h-8 w-8 rounded-lg">
        <span class="text-base font-semibold text-white tracking-tight">{{ $company->name ?? 'Storify' }}</span>
    </div>

    <nav class="flex-1 overflow-y-auto sidebar-scroll px-3 py-4 space-y-1 text-sm" x-data="{ openGroup: {{ $activeSidebarGroup ? "'" . $activeSidebarGroup . "'" : 'null' }} }">
        
        @if($sidebarUser?->business)
        <div class="px-3 pb-3 mb-1 border-b border-slate-800">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Business</p>
            <p class="text-xs text-slate-300 mt-0.5 truncate">{{ $sidebarUser->business->name }}</p>
        </div>
        @endif

        {{-- Dashboard --}}
        <a href="{{ route('management.dashboard') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.dashboard') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-home text-base w-5 text-center"></i>
            <span>Dashboard</span>
        </a>

        {{-- Stores --}}
        @can('stores view')
        <a href="{{ route('management.stores.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.stores.*') || request()->routeIs('management.orders.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-shop text-base w-5 text-center"></i>
            <span>Stores</span>
            @if($sidebarStoreCount > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-slate-700 text-[10px] font-bold text-slate-300 px-1.5">{{ $sidebarStoreCount }}</span>
            @endif
        </a>
        @endcan

        {{-- Warehouses --}}
        @can('warehouses view')
        <a href="{{ route('management.warehouses.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.warehouses.*') || request()->routeIs('management.sections.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-warehouse-alt text-base w-5 text-center"></i>
            <span>Warehouses</span>
            @if($sidebarWarehouses->count() > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-slate-700 text-[10px] font-bold text-slate-300 px-1.5">{{ $sidebarWarehouses->count() }}</span>
            @endif
        </a>
        @endcan

        {{-- Products --}}
        @can('products view')
        <div>
            <button @click="openGroup = openGroup === 'products' ? null : 'products'"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg w-full transition-colors {{ request()->routeIs('management.products.*') || request()->routeIs('management.categories.*') || request()->routeIs('management.services.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                <i class="fi fi-rr-cube text-base w-5 text-center"></i>
                <span class="flex-1 text-left text-xs font-semibold uppercase tracking-wider">Products</span>
                <i class="fi fi-rr-angle-small-down text-xs transition-transform" :class="{ 'rotate-180': openGroup === 'products' }"></i>
            </button>
            <div x-show="openGroup === 'products'" x-collapse class="ml-5 mt-1 space-y-1 border-l border-slate-700 pl-3">
                <a href="{{ route('management.products.index') }}"
                   class="block px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('management.products.index') || request()->routeIs('management.products.show') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    <span class="truncate block">All Products</span>
                </a>
                <a href="{{ route('management.categories.index') }}"
                   class="block px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('management.categories.*') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    <span class="truncate block">Categories</span>
                </a>
                <a href="{{ route('management.services.index') }}"
                   class="block px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('management.services.*') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    <span class="truncate block">Services</span>
                </a>
                <div class="pt-1 mt-1 border-t border-slate-700/50">
                    @can('products create')
                    <a href="{{ route('management.products.create') }}" class="block px-3 py-1.5 rounded-lg text-[11px] text-slate-500 hover:text-slate-300 transition-colors">
                        <i class="fi fi-rr-plus mr-1.5 opacity-50"></i>Add Product
                    </a>
                    @endcan
                </div>
            </div>
        </div>
        @endcan

        {{-- Team --}}
        @can('staff view')
        <div class="pt-3 mt-1 border-t border-slate-800">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Team</p>
        </div>
        <a href="{{ route('management.staff.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.staff.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-users text-base w-5 text-center"></i>
            <span>Staff</span>
            @if(($sidebarStaffCount ?? 0) > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-slate-700 text-[10px] font-bold text-slate-300 px-1.5">{{ $sidebarStaffCount }}</span>
            @endif
        </a>
        <a href="{{ route('management.roles.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.roles.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-shield-keyhole text-base w-5 text-center"></i>
            <span>Roles & Permissions</span>
        </a>
        @endcan

        {{-- Customers --}}
        @can('customers view')
        <div class="pt-3 mt-1 border-t border-slate-800">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Customers</p>
        </div>
        <a href="{{ route('management.customers.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.customers.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-users-alt text-base w-5 text-center"></i>
            <span>Customers</span>
            @if(($sidebarCustomersCount ?? 0) > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-slate-700 text-[10px] font-bold text-slate-300 px-1.5">{{ $sidebarCustomersCount }}</span>
            @endif
        </a>
        @endcan

        {{-- Orders --}}
        @can('orders view')
        <a href="{{ route('management.orders.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.orders.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-shopping-cart text-base w-5 text-center"></i>
            <span>Orders</span>
            @if(($sidebarPendingOrdersCount ?? 0) > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-amber-500 text-[10px] font-bold text-white px-1.5">{{ $sidebarPendingOrdersCount }}</span>
            @endif
        </a>
        @endcan

        {{-- Dispatches --}}
        @can('orders view')
        <a href="{{ route('management.dispatches.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.dispatches.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-truck-side text-base w-5 text-center"></i>
            <span>Dispatches</span>
            @if(($sidebarDispatchesCount ?? 0) > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-blue-500 text-[10px] font-bold text-white px-1.5">{{ $sidebarDispatchesCount }}</span>
            @endif
        </a>
        @endcan

        {{-- Inventory --}}
        @can('transfers view')
        <div class="pt-3 mt-1 border-t border-slate-800">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Inventory</p>
        </div>
        <a href="{{ route('management.transfers.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.transfers.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-exchange text-base w-5 text-center"></i>
            <span>Stock Adjustment</span>
        </a>
        @endcan

        {{-- POS --}}
        @can('pos view_history')
        <div>
            <button @click="openGroup = openGroup === 'pos' ? null : 'pos'"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg w-full transition-colors {{ request()->routeIs('management.pos.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                <i class="fi fi-rr-terminal text-base w-5 text-center"></i>
                <span class="flex-1 text-left text-xs font-semibold uppercase tracking-wider">POS</span>
                <span class="text-[10px] text-slate-500">{{ $sidebarPosOpenCount ?? 0 }}</span>
                <i class="fi fi-rr-angle-small-down text-xs transition-transform" :class="{ 'rotate-180': openGroup === 'pos' }"></i>
            </button>
            <div x-show="openGroup === 'pos'" x-collapse class="ml-5 mt-1 space-y-1 border-l border-slate-700 pl-3">
                <a href="{{ route('management.pos.index') }}"
                   class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('management.pos.index') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-plus text-xs"></i>
                    <span>Create New POS</span>
                </a>
                @forelse($sidebarPosStores ?? [] as $ps)
                <a href="{{ route('management.pos.terminal', $ps) }}"
                   class="block px-3 py-2 rounded-lg text-sm transition-colors {{ request()->route('store')?->id == $ps->id ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    <span class="truncate block">{{ $ps->name }}</span>
                    <span class="text-[10px] text-slate-500">{{ $ps->active_pos_sessions_count ?? 0 }} active</span>
                </a>
                @empty
                @endforelse
            </div>
        </div>
        @endcan

        {{-- Finance --}}
        @if($authUser?->can('transactions view') || $authUser?->can('settings payment'))
        <div class="pt-3 mt-1 border-t border-slate-800">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Finance</p>
        </div>
        @can('transactions view')
        <a href="{{ route('management.transactions.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.transactions.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-file-invoice-dollar text-base w-5 text-center"></i>
            <span>Transactions</span>
            @if(($sidebarPendingTransactionsCount ?? 0) > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-amber-500 text-[10px] font-bold text-white px-1.5">{{ $sidebarPendingTransactionsCount }}</span>
            @endif
        </a>
        @endcan
        @can('settings payment')
        <a href="{{ route('management.payment-settings.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.payment-settings.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-credit-card text-base w-5 text-center"></i>
            <span>Payment Settings</span>
        </a>
        @endcan
        <a href="{{ route('management.subscription.plan') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.subscription.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-bolt text-base w-5 text-center"></i>
            <span>Subscription</span>
        </a>
        @endif

        {{-- Account --}}
        <div class="pt-3 mt-1 border-t border-slate-800">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Account</p>
        </div>
        <a href="{{ route('management.profile.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.profile.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-user text-base w-5 text-center"></i>
            <span>Profile</span>
        </a>
        @if(auth()->user()?->isBusinessOwner())
        <a href="{{ route('management.kyc.show') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.kyc.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-document text-base w-5 text-center"></i>
            <span>KYC Verification</span>
        </a>
        @endif
        @can('support view_tickets')
        <a href="{{ route('management.support-messages.index') }}" 
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('management.support-messages.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-headset text-base w-5 text-center"></i>
            <span>Support</span>
        </a>
        @endcan
    </nav>

    {{-- Bottom --}}
    <div class="border-t border-slate-800 p-3">
        <a href="#" onclick="event.preventDefault(); document.getElementById('mgmt-logout-form').submit();"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-slate-800 transition-colors text-sm">
            <i class="fi fi-rr-exit text-base w-5 text-center"></i>
            <span>Logout</span>
        </a>
        <form id="mgmt-logout-form" action="{{ route('management.auth.logout') }}" method="POST" class="hidden">
            @csrf
        </form>
    </div>
</aside>
