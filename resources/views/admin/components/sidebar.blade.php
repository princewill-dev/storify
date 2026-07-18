{{-- Mobile overlay --}}
<div x-show="mobileMenuOpen" x-cloak @click="mobileMenuOpen = false" class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

{{-- Sidebar --}}
<aside class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 flex flex-col transform transition-transform duration-200 lg:translate-x-0"
    :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen }">

    {{-- Brand --}}
    <div class="flex items-center justify-between h-16 px-5 border-b border-slate-800 shrink-0">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5">
            <img src="{{ $company->favicon }}" alt="" class="h-7 w-7 rounded-lg">
            <div>
                <span class="text-sm font-semibold text-white tracking-tight">{{ $company->name ?? 'Storify' }}</span>
                <p class="text-[10px] text-slate-500 font-medium -mt-0.5">Admin Panel</p>
            </div>
        </a>
        <button @click="sidebarOpen = !sidebarOpen" class="hidden lg:flex items-center justify-center w-7 h-7 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800">
            <i class="fi fi-rr-angle-left text-xs" :class="{ 'rotate-180': !sidebarOpen }"></i>
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto sidebar-scroll px-3 py-4 space-y-5">

        {{-- Main Section --}}
        <div>
            <p class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Navigation</p>
            <div class="space-y-0.5">
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.dashboard') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-home text-base w-5 text-center"></i>
                    <span>Dashboard</span>
                </a>

                {{-- Businesses --}}
                <div x-data="{ open: {{ request()->routeIs('admin.vendors.*', 'admin.vendor-kyc.*', 'admin.early-access.*') ? 'true' : 'false' }} }">
                    <button @click="open = !open" class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors w-full text-left text-slate-300 hover:text-white hover:bg-slate-800">
                        <i class="fi fi-rr-building text-base w-5 text-center"></i>
                        <span class="flex-1">Businesses</span>
                        <i class="fi fi-rr-angle-small-down text-xs transition-transform duration-150" :class="{ 'rotate-180': open }"></i>
                    </button>
                    <div x-show="open" x-transition class="ml-4 space-y-0.5 mt-0.5">
                        <a href="{{ route('admin.vendors.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('admin.vendors.*') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                            <span>All Businesses</span>
                        </a>
                        <a href="{{ route('admin.vendor-kyc.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('admin.vendor-kyc.*') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                            <span>KYC Submissions</span>
                            @php $pendingKyc = \App\Models\KycApplication::where('status', 'submitted')->count(); @endphp
                            @if($pendingKyc)
                            <span class="ml-auto text-[10px] font-semibold text-amber-400 bg-amber-400/10 px-1.5 py-0.5 rounded-full">{{ $pendingKyc }}</span>
                            @endif
                        </a>
                        <a href="{{ route('admin.early-access.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ request()->routeIs('admin.early-access.*') ? 'text-white bg-slate-800' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
                            <span>Access Codes</span>
                        </a>
                    </div>
                </div>

                <a href="{{ route('admin.stores.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.stores.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-store-alt text-base w-5 text-center"></i>
                    <span>Stores</span>
                </a>

                <a href="{{ route('admin.warehouses.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.warehouses.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-warehouse-alt text-base w-5 text-center"></i>
                    <span>Warehouses</span>
                </a>

                <a href="{{ route('admin.transfers.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.transfers.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-arrows-exchange text-base w-5 text-center"></i>
                    <span>Stock Transfers</span>
                </a>

                <a href="{{ route('admin.subscriptions.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.subscriptions.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-credit-card text-base w-5 text-center"></i>
                    <span>Subscriptions</span>
                </a>
            </div>
        </div>

        {{-- Commerce Section --}}
        <div>
            <p class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Commerce</p>
            <div class="space-y-0.5">
                <a href="{{ route('admin.products.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.products.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-box text-base w-5 text-center"></i>
                    <span>Products</span>
                </a>
                <a href="{{ route('admin.categories.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.categories.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-apps text-base w-5 text-center"></i>
                    <span>Categories</span>
                </a>
                <a href="{{ route('admin.customers.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.customers.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fa-solid fa-users text-base w-5 text-center"></i>
                    <span>Customers</span>
                    @php $custCount = \App\Models\Customer::count(); @endphp
                    @if($custCount)
                    <span class="ml-auto text-[10px] font-semibold text-slate-500 bg-slate-700 px-1.5 py-0.5 rounded-full">{{ number_format($custCount) }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.orders.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.orders.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-shopping-bag text-base w-5 text-center"></i>
                    <span>Orders</span>
                    @php $pendingOrders = \App\Models\Order::where('status', 'pending')->count(); @endphp
                    @if($pendingOrders)
                    <span class="ml-auto text-[10px] font-semibold text-amber-400 bg-amber-400/10 px-1.5 py-0.5 rounded-full">{{ $pendingOrders }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.transactions.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.transactions.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-file-invoice-dollar text-base w-5 text-center"></i>
                    <span>Transactions</span>
                </a>
            </div>
        </div>

        {{-- Content Section --}}
        <div>
            <p class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Content</p>
            <div class="space-y-0.5">
                <a href="{{ route('admin.support-messages.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.support-messages.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-headset text-base w-5 text-center"></i>
                    <span>Support Messages</span>
                </a>
                <a href="{{ route('admin.testimonials.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.testimonials.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-comment-dots text-base w-5 text-center"></i>
                    <span>Testimonials</span>
                </a>
                <a href="{{ route('admin.features.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.features.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-star text-base w-5 text-center"></i>
                    <span>Features</span>
                </a>
            </div>
        </div>

        {{-- Settings Section --}}
        <div>
            <p class="px-3 mb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Settings</p>
            <div class="space-y-0.5">
                <a href="{{ route('admin.settings.edit') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.settings.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-settings text-base w-5 text-center"></i>
                    <span>Platform Settings</span>
                </a>
                <a href="{{ route('admin.subscription-plans.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.subscription-plans.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-tags text-base w-5 text-center"></i>
                    <span>Plans & Pricing</span>
                </a>
                <a href="{{ route('admin.payment-methods.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.payment-methods.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-credit-card text-base w-5 text-center"></i>
                    <span>Payment Methods</span>
                </a>
                <a href="{{ route('admin.bank-accounts.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.bank-accounts.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-bank text-base w-5 text-center"></i>
                    <span>Bank Accounts</span>
                </a>
                <a href="{{ route('admin.vats.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.vats.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-percentage text-base w-5 text-center"></i>
                    <span>VAT Settings</span>
                </a>
                <a href="{{ route('admin.delivery-routes.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.delivery-routes.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-route text-base w-5 text-center"></i>
                    <span>Delivery Routes</span>
                </a>
                <a href="{{ route('admin.delivery-intervals.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.delivery-intervals.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-time-fast text-base w-5 text-center"></i>
                    <span>Delivery Intervals</span>
                </a>
                <a href="{{ route('admin.company-services.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.company-services.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-bulb text-base w-5 text-center"></i>
                    <span>Company Services</span>
                </a>
                <a href="{{ route('admin.business-types.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.business-types.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-document text-base w-5 text-center"></i>
                    <span>Business Types</span>
                </a>
                <a href="{{ route('admin.ownership-types.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.ownership-types.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-document text-base w-5 text-center"></i>
                    <span>Ownership Types</span>
                </a>
                <a href="{{ route('admin.styling.index') }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.styling.*') ? 'bg-slate-800 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800' }}">
                    <i class="fi fi-rr-palette text-base w-5 text-center"></i>
                    <span>Page Styling</span>
                </a>
            </div>
        </div>

    </nav>

    {{-- Footer --}}
    <div class="px-3 py-3 border-t border-slate-800 shrink-0 space-y-0.5">
        <a href="{{ route('admin.activity-logs.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors {{ request()->routeIs('admin.activity-logs.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800' }}">
            <i class="fi fi-rr-list-check text-base w-5 text-center"></i>
            <span>Activity Logs</span>
        </a>
        <a href="{{ route('home.index') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors text-slate-400 hover:text-white hover:bg-slate-800">
            <i class="fi fi-rr-home text-base w-5 text-center"></i>
            <span>Back to Home</span>
        </a>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors w-full text-left text-slate-400 hover:text-red-400 hover:bg-slate-800">
                <i class="fi fi-rr-exit text-base w-5 text-center"></i>
                <span>Logout</span>
            </button>
        </form>
    </div>
</aside>
