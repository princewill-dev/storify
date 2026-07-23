@extends('management.layout')
@section('subtitle', $store->name)

@section('content')
<div x-data="storeTabs('{{ $store->store_id }}')" x-on:popstate.window="handlePopState($event)">

<x-management.page-header :breadcrumbs="$breadcrumbs" :title="$store->name" subtitle="Store Dashboard · {{ $store->store_id }}">
    <x-slot:actions>
        @if($store->has_website && $store->slug)
        <a href="{{ config('app.env') === 'local' ? url($store->slug) : 'https://' . $store->slug . '.' . config('app.main_domain', 'storify.ng') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 hover:bg-blue-100 rounded-lg transition-colors">
            <i class="fi fi-rr-globe text-xs"></i> Visit Storefront
        </a>
        @endif
        @if($store->pos_enabled)
        <a href="{{ route('pos.index') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-slate-900 hover:bg-slate-800 rounded-lg transition-colors">
            <i class="fi fi-rr-terminal text-xs"></i> Open POS Portal
        </a>
        @endif
        <a href="{{ route('management.transfers.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 rounded-lg transition-colors">
            <i class="fi fi-rr-arrows-exchange text-xs"></i> Stock Adjustment
        </a>
    </x-slot:actions>
</x-management.page-header>

{{-- Tab Bar --}}
<div class="flex items-center gap-1 mb-6 border-b border-slate-200 overflow-x-auto">
    <button @click="switchTab('dashboard')" :class="activeTab === 'dashboard' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Dashboard</button>
    @can('products view')
    <button @click="switchTab('products')" :class="activeTab === 'products' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Products</button>
    @endcan
    @can('orders view')
    <button @click="switchTab('orders')" :class="activeTab === 'orders' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Sales</button>
    @endcan
    <button @click="switchTab('transactions')" :class="activeTab === 'transactions' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Transactions</button>
    <button @click="switchTab('customers')" :class="activeTab === 'customers' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Customers</button>
    <button @click="switchTab('invoices')" :class="activeTab === 'invoices' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Invoices</button>
    @can('staff view')
    <button @click="switchTab('staff')" :class="activeTab === 'staff' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Staff</button>
    @endcan
    @if($store->has_website)
    <button @click="switchTab('web-metrics')" :class="activeTab === 'web-metrics' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Web Store</button>
    @endif
    <button @click="switchTab('settings')" :class="activeTab === 'settings' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap -mb-px transition-colors">Settings</button>
</div>

{{-- Dashboard Content (pre-rendered) --}}
<div x-show="activeTab === 'dashboard'">
    {{-- Metric Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-management.metric-card
            :value="'₦' . number_format($revenueThisMonth, 0)"
            label="Revenue (This Month)"
            :subtitle="$revenueChange >= 0 ? '+' . $revenueChange . '% vs last month' : $revenueChange . '% vs last month'"
            icon="fi-rr-chart-histogram" />

        <x-management.metric-card
            :value="$totalOrders"
            label="Total Sales"
            :subtitle="$pendingOrders . ' pending · ' . $completedOrders . ' completed'"
            icon="fi-rr-box-alt" />

        <x-management.metric-card
            :value="$productCount"
            label="Products"
            :subtitle="$activeProducts . ' active · ' . number_format($totalStock) . ' in stock'"
            icon="fi-rr-cube" />

        <x-management.metric-card
            :value="$customerCount"
            label="Customers"
            subtitle="Unique buyers"
            icon="fi-rr-users-alt" />
    </div>

    {{-- Revenue Chart --}}
    @if(!empty($monthlyRevenue))
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-slate-800">Revenue Overview</h3>
        </div>
        <div id="storeRevenueChart" style="height: 300px;"></div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Recent Orders --}}
        <div class="lg:col-span-2">
            <x-management.card header="Recent Sales">
                <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                    @forelse($recentOrders as $order)
                    <a href="{{ route('management.orders.show', $order) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-slate-800">{{ $order->order_number }}</span>
                                <x-management.status-badge :status="$order->status" />
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $order->customer?->first_name ?? 'Walk-in' }} · {{ $order->created_at->diffForHumans() }}</p>
                        </div>
                        <span class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span>
                    </a>
                    @empty
                    <div class="px-5 py-6 text-center text-sm text-slate-400">No sales yet</div>
                    @endforelse
                </div>
            </x-management.card>
        </div>

        {{-- Right Sidebar --}}
        <div class="space-y-6">
            {{-- Web Storefront --}}
            <x-management.card header="Web Storefront">
                @if($store->has_website)
                <div class="text-center space-y-3">
                    <div class="flex items-center gap-2 justify-center">
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span class="text-sm font-medium text-emerald-700">Live</span>
                    </div>
                    <p class="text-xs text-slate-500 truncate">{{ $store->slug }}.{{ config('app.main_domain', 'storify.ng') }}</p>
                    @php
                        $storeUrl = $store->slug
                            ? (config('app.env') === 'local' ? url($store->slug) : 'https://' . $store->slug . '.storify.ng')
                            : null;
                    @endphp
                    @if($storeUrl)
                    <a href="{{ $storeUrl }}" target="_blank" class="block w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">Visit Store</a>
                    @endif
                </div>
                @else
                <div class="text-center py-2">
                    <p class="text-sm text-slate-500 mb-3">No online storefront yet</p>
                    <button type="button" onclick="openWebsiteModal()" class="w-full py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition-colors">Enable Web Storefront</button>
                </div>
                @endif
            </x-management.card>

            {{-- POS --}}
            <x-management.card header="POS Terminal">
                @if($store->pos_enabled)
                    @if($activePosSession)
                    <div class="space-y-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-sm font-medium text-emerald-700">Session Open</span>
                        </div>
                        <div class="text-xs text-slate-500 space-y-1">
                            <div class="flex justify-between"><span>Opened by</span><span class="font-medium text-slate-700">{{ $activePosSession->staff->name }}</span></div>
                            <div class="flex justify-between"><span>Since</span><span class="font-medium text-slate-700">{{ $activePosSession->opened_at->format('d M, h:i A') }}</span></div>
                            <div class="flex justify-between"><span>Float</span><span class="font-medium text-slate-700">₦{{ number_format($activePosSession->opening_balance / 100, 2) }}</span></div>
                        </div>
                        <form method="POST" action="{{ route('management.pos.close', $store) }}" class="space-y-2 border-t border-slate-100 pt-3">
                            @csrf
                            <input type="number" name="closing_balance_actual" required class="block w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="Cash counted (kobo)">
                            <button type="submit" class="w-full py-2 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors">Close Session</button>
                        </form>
                    </div>
                    @else
                    <form method="POST" action="{{ route('management.pos.open', $store) }}" class="space-y-3">
                        @csrf
                        <input type="number" name="opening_balance" required class="block w-full rounded-lg border-slate-300 px-3 py-2 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="Opening cash float (kobo)">
                        <button type="submit" class="w-full py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg hover:bg-slate-800 transition-colors">Open Session</button>
                    </form>
                    @endif
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

            {{-- Low Stock --}}
            <x-management.card header="Low Stock">
                <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                    @forelse($lowStockProducts as $p)
                    <a href="{{ route('management.products.show', $p) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ $p->name }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 shrink-0">Qty: {{ $p->quantity }}</span>
                    </a>
                    @empty
                    <div class="px-5 py-4 text-center text-sm text-slate-400">All products well stocked</div>
                    @endforelse
                </div>
                @if($outOfStock > 0)
                <div class="px-5 py-2 border-t border-slate-100 -mx-5 -mb-5 bg-slate-50 text-center">
                    <span class="text-xs text-red-600 font-medium">{{ $outOfStock }} product(s) out of stock</span>
                </div>
                @endif
            </x-management.card>
        </div>
    </div>
</div>

{{-- AJAX Tab Content Area --}}
<div x-show="activeTab !== 'dashboard'" x-cloak>
    {{-- Loading Spinner --}}
    <div x-show="loading" class="flex items-center justify-center py-20">
        <div class="text-center space-y-3">
            <div class="inline-block w-8 h-8 border-2 border-slate-200 border-t-slate-600 rounded-full animate-spin"></div>
            <p class="text-sm text-slate-400">Loading <span x-text="activeTab"></span>...</p>
        </div>
    </div>

    {{-- Error State --}}
    <div x-show="error" x-cloak class="text-center py-20">
        <i class="fi fi-rr-exclamation text-4xl text-red-300 mb-3 block"></i>
        <p class="text-sm text-red-500" x-text="error"></p>
        <button @click="fetchTab(activeTab)" class="mt-3 px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50">Retry</button>
    </div>

    {{-- Tab Content --}}
    <div x-show="!loading && !error" x-html="tabContent[activeTab] || ''" @click="handleTabClick($event)"></div>
</div>

</div>

@if(!empty($monthlyRevenue))
@push('head-scripts')
<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
@endpush
@push('scripts')
<script>
(function () {
    var options = {
        chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [{ name: 'Revenue', data: @js(array_column($monthlyRevenue, 'total')) }],
        xaxis: { categories: @js(array_column($monthlyRevenue, 'month')), labels: { style: { colors: '#94a3b8', fontSize: '12px' } } },
        yaxis: { labels: { formatter: function(v) { return '₦' + (v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'k' : v); }, style: { colors: '#94a3b8', fontSize: '12px' } } },
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05, stops: [0, 100] } },
        colors: ['#2563eb'],
        tooltip: { y: { formatter: function(v) { return '₦' + v.toLocaleString(); } } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
        dataLabels: { enabled: false }
    };
    new ApexCharts(document.getElementById('storeRevenueChart'), options).render();
})();
</script>
@endpush
@endif

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('storeTabs', (storeId) => ({
        activeTab: 'dashboard',
        loading: false,
        error: null,
        tabContent: {},
        baseUrl: `/management/stores/${storeId}/tab`,

        init() {
            // Restore tab from URL hash on page load
            const hash = window.location.hash.replace('#', '');
            const validTabs = ['dashboard', 'products', 'orders', 'transactions', 'customers', 'invoices', 'staff', 'web-metrics', 'settings'];
            if (hash && validTabs.includes(hash)) {
                this.switchTab(hash);
            }

            // Listen for browser back/forward
            window.addEventListener('hashchange', () => {
                const newHash = window.location.hash.replace('#', '');
                if (newHash && validTabs.includes(newHash) && newHash !== this.activeTab) {
                    this.switchTab(newHash);
                }
            });

            // Listen for custom tab-switch events from loaded tab content
            window.addEventListener('store-tab-switch', (e) => {
                const { tab, scrollTo } = e.detail;
                if (tab && validTabs.includes(tab)) {
                    this._pendingScroll = scrollTo || null;
                    this.switchTab(tab);
                }
            });
        },

        switchTab(tab) {
            if (this.activeTab === tab) return;
            this.activeTab = tab;
            this.error = null;

            // Update URL hash for persistence
            if (tab !== 'dashboard') {
                window.location.hash = tab;
            } else {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }

            // Fetch if not already cached
            if (!this.tabContent[tab]) {
                this.fetchTab(tab);
            }
        },

        async fetchTab(tab, queryString = '') {
            this.loading = true;
            this.error = null;

            let url = `${this.baseUrl}/${tab}`;
            if (queryString) {
                url += '?' + queryString;
            }

            try {
                const resp = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html',
                    }
                });

                if (!resp.ok) {
                    throw new Error(`Server error (${resp.status})`);
                }

                const html = await resp.text();
                this.tabContent[tab] = html;

                // Scroll to target element if requested
                if (this._pendingScroll) {
                    this.$nextTick(() => {
                        const el = document.getElementById(this._pendingScroll);
                        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                        this._pendingScroll = null;
                    });
                }
            } catch (e) {
                console.error('Tab fetch error:', e);
                this.error = e.message || 'Failed to load content.';
            } finally {
                this.loading = false;
            }
        },

        // Handle pagination and filter clicks inside loaded tab content
        handleTabClick(event) {
            const link = event.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');
            if (!href || href === '#') return;

            // Intercept tab pagination/filter links to stay within the tab
            if (href.includes('/tab/') || link.closest('.pagination') || link.closest('nav[role="navigation"]') || href.includes('page=')) {
                event.preventDefault();
                const url = new URL(href, window.location.origin);
                const tab = url.pathname.split('/tab/')[1]?.split('/')[0] || this.activeTab;
                this.fetchTab(tab, url.searchParams.toString());
                return;
            }

            // Allow full navigation for links to other management pages
        },

        // Handle browser back/forward
        handlePopState(event) {
            if (event.state && event.state.tab) {
                this.switchTab(event.state.tab);
            }
        }
    }));
});
</script>
@endpush

{{-- Enable Web Storefront Modal --}}
@unless($store->has_website)
<div id="websiteModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeWebsiteModal()"></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Enable Web Storefront</h3>
            <form method="POST" action="{{ route('management.stores.enable-website', $store) }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Store Name <span class="text-red-500">*</span></label>
                    <input type="text" id="wsStoreName" name="store_name" value="{{ $store->name }}" required
                           class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm"
                           oninput="checkStoreSlug()">
                </div>

                <input type="hidden" name="slug" id="wsSlugInput">

                <div id="wsSlugPreview" class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                    <div class="flex items-center gap-2">
                        <i class="fi fi-rr-globe text-slate-400 text-sm shrink-0"></i>
                        <span id="wsSlugUrl" class="text-sm font-medium text-slate-700 truncate">Enter a store name above</span>
                        <span id="wsSlugStatus" class="text-xs font-medium shrink-0"></span>
                    </div>
                </div>

                <hr class="border-slate-100">

                <div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_nationwide" value="1" onchange="toggleNationwideFields()"
                               class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                        <span class="text-sm font-medium text-slate-700">Nationwide Delivery</span>
                    </label>
                    <p class="text-xs text-slate-400 mt-1 ml-6">Flat rate delivery to all states in Nigeria</p>
                </div>

                <div id="wsNationwideFields" class="hidden space-y-3 pl-6">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Delivery Fee (₦)</label>
                            <input type="number" name="nationwide_fee" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="e.g. 2000" min="0">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Delivery Days</label>
                            <input type="number" name="nationwide_days" class="block w-full rounded-lg border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="e.g. 3" min="1">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="closeWebsiteModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-700 text-sm font-semibold rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Enable Storefront</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endunless
@endsection

@push('scripts')
<script>
var wsSlugTimer = null;

function openWebsiteModal() { document.getElementById('websiteModal').classList.remove('hidden'); checkStoreSlug(); }
function closeWebsiteModal() { document.getElementById('websiteModal').classList.add('hidden'); }
function toggleNationwideFields() {
    document.getElementById('wsNationwideFields').classList.toggle('hidden', !document.querySelector('input[name="is_nationwide"]').checked);
}

function checkStoreSlug() {
    var name = document.getElementById('wsStoreName').value.trim();
    if (name.length < 2) { document.getElementById('wsSlugUrl').textContent = 'Enter a store name above'; document.getElementById('wsSlugStatus').textContent = ''; document.getElementById('wsSlugInput').value = ''; return; }

    clearTimeout(wsSlugTimer);
    document.getElementById('wsSlugStatus').textContent = 'Checking...';

    wsSlugTimer = setTimeout(function () {
        fetch('/management/stores/check-slug', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ name: name })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('wsSlugUrl').textContent = data.url;
            document.getElementById('wsSlugInput').value = data.slug || '';
            if (data.available) {
                document.getElementById('wsSlugStatus').innerHTML = '<span class="text-emerald-600 font-medium">✓ Available</span>';
            } else if (data.slug) {
                document.getElementById('wsSlugStatus').textContent = 'Suggested: ' + data.slug;
            }
        })
        .catch(function () {
            document.getElementById('wsSlugStatus').textContent = '';
        });
    }, 500);
}
</script>
@endpush
