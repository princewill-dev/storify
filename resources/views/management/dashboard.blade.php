@extends('management.layout')
@section('subtitle', 'Dashboard')

@section('content')

{{-- Page Header --}}
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Dashboard" subtitle="{{ $activeStoreObj ? $activeStoreObj->name . ' · Store Overview' : 'Business Overview' }}">
    <x-slot:actions>
        @can('stores view')
        @if($stats['total_stores'] > 0)
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 rounded-lg transition-colors">
                <i class="fi fi-rr-shop text-blue-500 text-xs"></i>
                <span class="max-w-[120px] truncate">{{ $activeStoreObj?->name ?? 'All Stores' }}</span>
                <i class="fi fi-rr-angle-small-down text-xs"></i>
            </button>
            <div x-show="open" @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50">
                <p class="px-3 py-2 text-xs font-semibold text-slate-400 uppercase">Switch View</p>
                <a href="javascript:void(0)" onclick="event.preventDefault(); document.getElementById('ds-clear-store').submit();"
                   class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ !$activeStoreObj ? 'bg-blue-50 text-blue-700' : '' }}">
                    <i class="fi fi-rr-apps w-4 text-center {{ !$activeStoreObj ? 'text-blue-500' : 'text-slate-400' }}"></i> All Stores (Combined)
                </a>
                <form id="ds-clear-store" action="{{ route('management.stores.switch') }}" method="POST" class="hidden">@csrf <input type="hidden" name="store_id" value=""></form>
                @foreach($user->stores->where('status', '!=', 'deleted') as $st)
                <a href="javascript:void(0)" onclick="event.preventDefault(); document.getElementById('ds-switch-{{ $st->id }}').submit();"
                   class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ ($activeStoreObj && (int)$activeStoreObj->id === (int)$st->id) ? 'bg-blue-50 text-blue-700' : '' }}">
                    <i class="fi fi-rr-shop w-4 text-center {{ ($activeStoreObj && (int)$activeStoreObj->id === (int)$st->id) ? 'text-blue-500' : 'text-slate-300' }}"></i>
                    {{ $st->name }}
                </a>
                <form id="ds-switch-{{ $st->id }}" action="{{ route('management.stores.switch') }}" method="POST" class="hidden">@csrf <input type="hidden" name="store_id" value="{{ $st->id }}"></form>
                @endforeach
            </div>
        </div>
        @endif
        @endcan
    </x-slot:actions>
</x-management.page-header>

@if(!$user->business?->hasActiveSubscription())
    @if($user->isOnTrial() && $user->daysLeftOnTrial() <= 2)
    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-center justify-between">
        <div>
            <p class="font-semibold text-blue-800">Your free trial ends in {{ $user->daysLeftOnTrial() }} day{{ $user->daysLeftOnTrial() !== 1 ? 's' : '' }}</p>
            <p class="text-sm text-blue-600 mt-0.5">Subscribe now to keep your stores running smoothly.</p>
        </div>
        <a href="{{ route('management.subscription.payment') }}" class="shrink-0 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">Upgrade Now</a>
    </div>
    @elseif($user->trialHasExpired())
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center justify-between">
        <div>
            <p class="font-semibold text-red-800">Your free trial has expired</p>
            <p class="text-sm text-red-600 mt-0.5">Your stores are paused. Subscribe to reactivate them.</p>
        </div>
        <a href="{{ route('management.subscription.payment') }}" class="shrink-0 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors">Subscribe Now</a>
    </div>
    @elseif($user->selected_plan_id && !$user->isOnTrial())
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between">
        <div>
            <p class="font-semibold text-amber-800">Complete your subscription</p>
            <p class="text-sm text-amber-600 mt-0.5">You chose the <strong>{{ $user->selectedPlan?->name }}</strong> plan. Pay now to activate your stores.</p>
        </div>
        <a href="{{ route('management.subscription.payment') }}" class="shrink-0 px-4 py-2 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700 transition-colors">Pay Now</a>
    </div>
    @elseif(!$user->isOnTrial() && $user->business?->needsSubscription())
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between">
        <div>
            <p class="font-semibold text-amber-800">Complete your subscription</p>
            <p class="text-sm text-amber-600 mt-0.5">Select a plan to activate your stores and start selling.</p>
        </div>
        <a href="{{ route('management.subscription.plan') }}" class="shrink-0 px-4 py-2 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700 transition-colors">Choose Plan</a>
    </div>
    @endif
@endif

{{-- Metric Cards --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    @can('transactions view')
    <x-management.metric-card 
        label="Total Revenue" value="₦{{ number_format($stats['total_revenue'], 2) }}"
        subtitle="{{ $stats['revenue_change_percent'] > 0 ? '+' : '' }}{{ $stats['revenue_change_percent'] }}% vs last month"
        icon="fi fi-rr-usd-circle" />
    @endcan
    @can('products view')
    <x-management.metric-card 
        label="Products" :value="number_format($stats['total_products'])"
        subtitle="{{ number_format($stats['total_stock']) }} in stock"
        icon="fi fi-rr-cube" />
    <x-management.metric-card 
        label="Stock Value" value="₦{{ number_format($stats['stock_value'], 2) }}"
        subtitle="Active inventory valuation"
        icon="fi fi-rr-chart-histogram" />
    @endcan
    @can('orders view')
    <x-management.metric-card label="Orders" :value="number_format($stats['total_orders'])"
        subtitle="{{ $stats['pending_orders'] }} pending · {{ $stats['orders_this_month'] }} this month"
        icon="fi fi-rr-shopping-cart" />
    @endcan
    @can('customers view')
    <x-management.metric-card label="Customers" :value="number_format($stats['total_customers'])"
        subtitle="{{ $stats['active_customers'] }} active"
        icon="fi fi-rr-users-alt" />
    @endcan
    @can('warehouses view')
    <x-management.metric-card 
        label="Warehouses" :value="$stats['total_warehouses']"
        subtitle="{{ number_format($stats['warehouse_total_stock']) }} items stocked"
        icon="fi fi-rr-warehouse-alt" />
    @endcan
    @can('transfers view')
    <x-management.metric-card 
        label="Transfer Requests" :value="$stats['pending_transfers']"
        subtitle="Pending approval"
        icon="fi fi-rr-truck-loading" />
    @endcan
    @can('pos view_history')
    <x-management.metric-card label="Open POS" :value="$stats['open_pos_sessions']->count()"
        subtitle="{{ $stats['active_pos_stores'] }} stores enabled"
        icon="fi fi-rr-terminal" />
    @endcan
    @can('stores view')
    @if($stats['web_visits'] > 0)
    <x-management.metric-card label="Web Visits" :value="$stats['web_visits']"
        subtitle="Online stores"
        icon="fi fi-rr-globe" />
    @endif
    @endcan
    @can('staff view')
    <x-management.metric-card 
        label="Total Staff" :value="$stats['total_staff']"
        subtitle="{{ $stats['active_staff'] }} active"
        icon="fi fi-rr-users" />
    @endcan
    @can('stores view')
    <x-management.metric-card 
        label="Stores" :value="$stats['total_stores']"
        subtitle="{{ $stats['active_stores'] }} active"
        icon="fi fi-rr-shop" />
    @endcan
</div>

{{-- Stock Transfer Requests Table --}}
@can('transfers view')
@if($stats['pending_transfer_list']->isNotEmpty())
<div class="mb-6">
    <x-management.card>
        <x-slot:header>
            <h3 class="text-sm font-semibold text-slate-800">Stock Transfer Requests</h3>
            <a href="{{ route('management.transfers.index', ['status' => 'pending']) }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">View all</a>
        </x-slot:header>
        <x-management.data-table>
            <x-slot:header>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Code</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">From</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">To</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Items</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Requested</th>
            </x-slot:header>
            @foreach($stats['pending_transfer_list'] as $transfer)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3">
                    <a href="{{ route('management.transfers.show', $transfer) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $transfer->transfer_code }}</a>
                </td>
                <td class="px-5 py-3">
                    <span class="text-sm text-slate-700">{{ $transfer->fromLocation?->name ?? '—' }}</span>
                </td>
                <td class="px-5 py-3">
                    <span class="text-sm text-slate-700">{{ $transfer->toLocation?->name ?? '—' }}</span>
                </td>
                <td class="px-5 py-3 text-center hidden sm:table-cell">
                    <span class="text-sm text-slate-600">{{ $transfer->items->count() }}</span>
                </td>
                <td class="px-5 py-3 text-center">
                    <x-management.status-badge :status="$transfer->status->value" />
                </td>
                <td class="px-5 py-3 hidden md:table-cell">
                    <span class="text-xs text-slate-400">{{ $transfer->created_at->diffForHumans() }}</span>
                </td>
            </tr>
            @endforeach
        </x-management.data-table>
    </x-management.card>
</div>
@endif
@endcan

{{-- Charts + Recent Orders Row --}}
@can('transactions view')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    {{-- Revenue Chart --}}
    <div class="lg:col-span-2">
        <x-management.card header="Revenue Overview">
            <div class="flex items-baseline gap-3 mb-4">
                <span class="text-2xl font-bold text-slate-900">₦{{ number_format($stats['revenue_this_month'], 2) }}</span>
                <span class="text-sm {{ $stats['revenue_change_percent'] >= 0 ? 'text-emerald-600' : 'text-red-500' }} font-medium">
                    {{ $stats['revenue_change_percent'] > 0 ? '+' : '' }}{{ $stats['revenue_change_percent'] }}% vs last month
                </span>
                <span class="text-xs text-slate-400 ml-auto">Last 6 months</span>
            </div>
            <div id="revenueChart" class="h-64"></div>
        </x-management.card>
    </div>

    {{-- Recent Orders --}}
    @can('orders view')
    <div>
        <x-management.card header="Recent Orders">
            <x-slot:header>
                <h3 class="text-sm font-semibold text-slate-800">Recent Orders</h3>
                <a href="{{ route('management.orders.index') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">View all</a>
            </x-slot:header>
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($stats['recent_orders'] as $order)
                <a href="{{ route('management.orders.show', $order) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-slate-800 truncate">{{ $order->order_number }}</span>
                            <x-management.status-badge :status="$order->status" />
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $order->store?->name ?? 'N/A' }} · {{ $order->items->count() }} items · {{ $order->created_at->diffForHumans() }}</p>
                    </div>
                    <span class="text-sm font-semibold text-slate-800 ml-3">₦{{ number_format($order->total, 2) }}</span>
                </a>
                @empty
                <div class="px-5 py-8 text-center text-sm text-slate-400">No orders yet</div>
                @endforelse
            </div>
        </x-management.card>
    </div>
    @endcan
</div>
@endcan

{{-- Low Stock, Staff, Warehouses Row --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    {{-- Low Stock --}}
    <x-management.card header="Low Stock Alerts">
        <x-slot:header>
            <h3 class="text-sm font-semibold text-slate-800">Low Stock</h3>
            @if($stats['out_of_stock'] > 0)
                <span class="text-xs font-medium text-red-600 bg-red-50 px-2 py-0.5 rounded-full">{{ $stats['out_of_stock'] }} out</span>
            @endif
        </x-slot:header>
        <div class="divide-y divide-slate-100 -mx-5 -mb-5">
            @forelse($stats['low_stock_products'] as $lp)
            <div class="flex items-center justify-between px-5 py-2.5">
                <div class="min-w-0">
                    <p class="text-sm text-slate-700 truncate">{{ $lp->name }}</p>
                    <p class="text-xs text-slate-400">{{ $lp->store?->name ?? 'N/A' }}</p>
                </div>
                <span class="text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">{{ $lp->quantity }} left</span>
            </div>
            @empty
            <div class="px-5 py-6 text-center text-sm text-slate-400">All products well stocked</div>
            @endforelse
        </div>
    </x-management.card>

    {{-- Staff --}}
    @can('staff view')
    <x-management.card header="Staff">
        <x-slot:header>
            <h3 class="text-sm font-semibold text-slate-800">Staff</h3>
            <a href="{{ route('management.staff.index') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Manage</a>
        </x-slot:header>
        <div class="divide-y divide-slate-100 -mx-5 -mb-5">
            @forelse($stats['recent_staff'] as $member)
            <div class="flex items-center justify-between px-5 py-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold text-white shrink-0 {{ $member->status === 'active' ? 'bg-emerald-500' : ($member->status === 'invited' ? 'bg-amber-500' : 'bg-red-500') }}">
                        {{ strtoupper(substr($member->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm text-slate-700 truncate">{{ $member->name }}</p>
                        <p class="text-xs text-slate-400">
                            @foreach($member->roles as $role) {{ $role->name }}@if(!$loop->last), @endif @endforeach
                        </p>
                    </div>
                </div>
                <x-management.status-badge :status="$member->status" />
            </div>
            @empty
            <div class="px-5 py-6 text-center">
                <p class="text-sm text-slate-400 mb-2">No staff members yet</p>
                <a href="{{ route('management.staff.create') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Invite your first team member</a>
            </div>
            @endforelse
        </div>
    </x-management.card>
    @endcan

    {{-- Warehouses --}}
    <x-management.card header="Warehouses">
        <x-slot:header>
            <h3 class="text-sm font-semibold text-slate-800">Warehouses</h3>
            <a href="{{ route('management.warehouses.index') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Manage</a>
        </x-slot:header>
        <div class="divide-y divide-slate-100 -mx-5 -mb-5">
            @forelse($stats['warehouses'] as $wh)
            <a href="{{ route('management.warehouses.show', $wh) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full {{ $wh->isActive() ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                        <span class="text-sm text-slate-700 font-medium truncate">{{ $wh->name }}</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $wh->city }}{{ $wh->city && $wh->state ? ', ' : '' }}{{ $wh->state }}</p>
                </div>
                <span class="text-xs text-slate-500">{{ $wh->stock_locations_count }} products</span>
            </a>
            @empty
            <div class="px-5 py-6 text-center">
                <p class="text-sm text-slate-400 mb-2">No warehouses yet</p>
                <a href="{{ route('management.warehouses.create') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Add your first warehouse</a>
            </div>
            @endforelse
        </div>
    </x-management.card>
</div>

{{-- Transactions Table --}}
@can('transactions view')
<div class="mb-6">
    <x-management.card>
        <x-slot:header>
            <h3 class="text-sm font-semibold text-slate-800">Recent Transactions</h3>
            <a href="{{ route('management.transactions.index') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">View all</a>
        </x-slot:header>
        <x-management.data-table>
            <x-slot:header>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Reference</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Order</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Customer</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Status</th>
                <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Date</th>
            </x-slot:header>
            @forelse($stats['recent_transactions'] as $tx)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-5 py-3">
                    <a href="{{ route('management.transactions.show', $tx) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $tx->reference }}</a>
                </td>
                <td class="px-5 py-3">
                    @if($tx->order)
                    <a href="{{ route('management.orders.show', $tx->order) }}" class="text-sm text-blue-600 hover:text-blue-700">{{ $tx->order->order_number }}</a>
                    @else <span class="text-sm text-slate-400">N/A</span> @endif
                </td>
                <td class="px-5 py-3 hidden sm:table-cell">
                    <span class="text-sm text-slate-600">{{ $tx->order?->customer?->first_name ?? 'Walk-in' }}</span>
                </td>
                <td class="px-5 py-3 text-right">
                    <span class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span>
                </td>
                <td class="px-5 py-3 text-center hidden md:table-cell">
                    <x-management.status-badge :status="$tx->status" />
                </td>
                <td class="px-5 py-3 text-right text-sm text-slate-400 hidden md:table-cell">{{ $tx->created_at->format('d M Y') }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-400">No transactions yet</td></tr>
            @endforelse
        </x-management.data-table>
    </x-management.card>
</div>
@endcan
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var revenueData = @json($stats['monthly_revenue']);
    var orderData = @json($stats['monthly_orders']);
    var months = revenueData.length ? revenueData.map(function(d) { return d.month; }) : [];
    
    if (document.getElementById('revenueChart')) {
        var options = {
            chart: { type: 'area', height: 256, toolbar: { show: false }, fontFamily: 'Inter, system-ui, sans-serif' },
            series: [{
                name: 'Revenue',
                data: revenueData.length ? revenueData.map(function(d) { return d.total; }) : [0,0,0,0,0,0],
            }],
            xaxis: { categories: months.length ? months : ['Jan','Feb','Mar','Apr','May','Jun'], labels: { style: { colors: '#94a3b8', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { labels: { formatter: function(v) { return '₦' + (v/1000).toFixed(0) + 'k'; }, style: { colors: '#94a3b8', fontSize: '11px' } } },
            grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2, colors: ['#2563eb'] },
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05, stops: [0, 100] } },
            tooltip: { y: { formatter: function(v) { return '₦' + v.toLocaleString(); } } },
        };
        new ApexCharts(document.getElementById('revenueChart'), options).render();
    }
});
</script>
@endpush
