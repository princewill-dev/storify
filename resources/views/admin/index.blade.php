@extends('admin.layout')
@section('subtitle', 'Dashboard')

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Admin Dashboard</h2>
        <p class="text-sm text-slate-500 mt-0.5">Platform overview and management</p>
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="date" name="from" value="{{ $fromDate instanceof \Illuminate\Support\Carbon ? $fromDate->toDateString() : '' }}" class="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        <span class="text-xs text-slate-400">to</span>
        <input type="date" name="to" value="{{ $toDate instanceof \Illuminate\Support\Carbon ? $toDate->toDateString() : '' }}" class="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
        <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
            <option value="">All Stores</option>
            @foreach($allStores as $st)
            <option value="{{ $st->id }}" @selected($filterStoreId == $st->id)>{{ $st->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-3 py-1.5 bg-slate-900 text-white text-xs font-medium rounded-lg hover:bg-slate-800">Filter</button>
        @if($filterStoreId || request('from') || request('to'))
        <a href="{{ route('admin.dashboard') }}" class="px-3 py-1.5 border border-slate-200 text-xs rounded-lg hover:bg-slate-50">Clear</a>
        @endif
    </form>
</div>

{{-- Top KPI Row --}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-chart-line text-emerald-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">₦{{ number_format($todaySales, 0) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Today's Revenue</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-shopping-bag text-blue-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">{{ number_format($todayOrders) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Today's Orders</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-chart-histogram text-indigo-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">₦{{ number_format($mtdRevenue, 0) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Revenue MTD</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-boxes text-amber-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">₦{{ number_format($stockValue, 0) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stock Value</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-store-alt text-violet-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">{{ number_format($stats['active_stores'] ?? 0) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Active Stores</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-3 text-center">
        <i class="fi fi-rr-users text-rose-500 text-lg mb-1 block"></i>
        <p class="text-lg font-bold text-slate-900">{{ number_format($stats['total_customers']) }}</p>
        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Customers</p>
    </div>
</div>

{{-- Charts Row --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Daily Revenue (30 Days)</h3>
        <div id="chartDailyRevenue" style="height:280px"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Payment Methods</h3>
        <div id="chartPaymentBreakdown" style="height:280px"></div>
    </div>
</div>

{{-- Stats Grid --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Total Revenue</p>
        <p class="text-xl font-bold text-slate-900 mt-1">₦{{ number_format($stats['total_revenue'] ?? 0, 0) }}</p>
        <p class="text-[10px] text-slate-400">{{ number_format($stats['total_transactions'] ?? 0) }} transactions</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Orders</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($stats['total_orders'] ?? 0) }}</p>
        <p class="text-[10px] text-amber-500">{{ number_format($stats['pending_orders'] ?? 0) }} pending</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Businesses</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($stats['total_businesses'] ?? 0) }}</p>
        <p class="text-[10px] text-slate-400">{{ number_format($stats['active_businesses'] ?? 0) }} active</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Stores</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($stats['total_stores'] ?? 0) }}</p>
        <p class="text-[10px] text-slate-400">{{ number_format($stats['active_stores'] ?? 0) }} active · {{ number_format($stats['total_warehouses'] ?? 0) }} warehouses</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Products</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($stats['total_products'] ?? 0) }}</p>
        <p class="text-[10px] text-slate-400">{{ number_format($stats['active_products'] ?? 0) }} active · {{ number_format($totalStock ?? 0) }} in stock</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Low Stock</p>
        <p class="text-xl font-bold text-amber-600 mt-1">{{ number_format($lowStockCount ?? 0) }}</p>
        <p class="text-[10px] text-red-500">{{ number_format($outOfStockCount ?? 0) }} out of stock</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">Staff</p>
        <p class="text-xl font-bold text-slate-900 mt-1">{{ number_format($stats['total_staff'] ?? 0) }}</p>
        <p class="text-[10px] text-slate-400">{{ number_format($stats['open_pos_sessions'] ?? 0) }} open POS sessions</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase">KYC Pending</p>
        <p class="text-xl font-bold text-amber-600 mt-1">{{ number_format($stats['kyc_pending'] ?? 0) }}</p>
        <a href="{{ route('admin.vendor-kyc.index', ['status' => 'submitted']) }}" class="text-[10px] text-blue-600 hover:underline">Review →</a>
    </div>
</div>

{{-- Daily Orders Chart --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
    <h3 class="text-sm font-semibold text-slate-800 mb-4">Daily Orders (30 Days)</h3>
    <div id="chartDailyOrders" style="height:250px"></div>
</div>

{{-- Store Performance Table --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
    <div class="px-5 py-3 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-800">Store Performance</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100">
                    <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Store</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Revenue Today</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Revenue MTD</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Orders Today</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Products</th>
                    <th class="px-5 py-2.5 text-center text-[11px] font-semibold text-slate-400 uppercase">POS</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase hidden sm:table-cell">Last Sale</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($stores as $store)
                <tr class="hover:bg-slate-50/50">
                    <td class="px-5 py-3">
                        <a href="{{ route('admin.stores.show', $store) }}" class="text-xs font-medium text-slate-800 hover:text-blue-600">{{ $store->name }}</a>
                        <p class="text-[10px] text-slate-400">{{ $store->user?->name }}</p>
                    </td>
                    <td class="px-5 py-3 text-right"><span class="text-xs font-semibold text-slate-800">₦{{ number_format($store->revenue_today ?? 0, 0) }}</span></td>
                    <td class="px-5 py-3 text-right"><span class="text-xs font-semibold text-slate-800">₦{{ number_format($store->revenue_mtd ?? 0, 0) }}</span></td>
                    <td class="px-5 py-3 text-right"><span class="text-xs text-slate-600">{{ number_format($store->orders_today ?? 0) }}</span></td>
                    <td class="px-5 py-3 text-right"><span class="text-xs text-slate-600">{{ number_format($store->products_count ?? 0) }}</span></td>
                    <td class="px-5 py-3 text-center">
                        @if($store->pos_status === 'open')
                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-600"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Live</span>
                        @else
                        <span class="text-[10px] text-slate-400">Offline</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-right hidden sm:table-cell">
                        <span class="text-[11px] text-slate-400">{{ $store->last_order_at ? $store->last_order_at->diffForHumans() : '—' }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-5 py-6 text-center text-sm text-slate-400">No stores found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pending Transfers + Transactions --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Pending Transfers</h3>
            <div class="flex items-center gap-3 text-[11px]">
                <span class="text-amber-600 font-medium">{{ $transferStats['pending'] ?? 0 }} pending</span>
                <span class="text-blue-600 font-medium">{{ $transferStats['today_dispatched'] ?? 0 }} dispatched today</span>
                <span class="text-emerald-600 font-medium">{{ $transferStats['today_received'] ?? 0 }} received today</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @forelse($pendingTransfers ?? [] as $transfer)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-5 py-3">
                            <a href="{{ route('admin.transfers.show', $transfer) }}" class="text-xs font-medium text-slate-800 hover:text-blue-600">{{ $transfer->transfer_code }}</a>
                            <p class="text-[10px] text-slate-400">{{ $transfer->fromLocation?->name }} → {{ $transfer->toLocation?->name }}</p>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $transfer->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ ucfirst($transfer->status) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="2" class="px-5 py-6 text-center text-sm text-slate-400">No pending transfers</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Recent Transactions</h3>
            <a href="{{ route('admin.transactions.index') }}" class="text-xs text-blue-600 hover:underline">View all</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-50">
                    <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Reference</th>
                    <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Store</th>
                    <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Amount</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($stats['recent_transactions'] ?? [] as $tx)
                    <tr class="hover:bg-slate-50/50">
                        <td class="px-5 py-3"><span class="text-xs font-mono text-slate-600">{{ \Illuminate\Support\Str::limit($tx->reference, 14) }}</span></td>
                        <td class="px-5 py-3"><span class="text-xs text-slate-600">{{ $tx->order?->store?->name ?? '—' }}</span></td>
                        <td class="px-5 py-3 text-right"><span class="text-xs font-semibold text-slate-800">₦{{ number_format($tx->amount, 2) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-slate-400">No recent transactions</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Recent Orders --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-800">Recent Orders</h3>
        <a href="{{ route('admin.orders.index') }}" class="text-xs text-blue-600 hover:underline">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-slate-50">
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Order</th>
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Customer</th>
                <th class="px-5 py-2.5 text-left text-[11px] font-semibold text-slate-400 uppercase">Store</th>
                <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Total</th>
                <th class="px-5 py-2.5 text-right text-[11px] font-semibold text-slate-400 uppercase">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($stats['recent_orders'] ?? [] as $order)
                <tr class="hover:bg-slate-50/50">
                    <td class="px-5 py-3"><span class="text-xs font-medium text-slate-700">#{{ $order->order_number ?? $order->id }}</span></td>
                    <td class="px-5 py-3"><span class="text-xs text-slate-600">{{ $order->customer?->full_name ?? 'Walk-in' }}</span></td>
                    <td class="px-5 py-3"><span class="text-xs text-slate-600">{{ $order->store?->name ?? '—' }}</span></td>
                    <td class="px-5 py-3 text-right"><span class="text-xs font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span></td>
                    <td class="px-5 py-3 text-right">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $order->status->value === 'completed' ? 'bg-emerald-50 text-emerald-700' : ($order->status->value === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600') }}">{{ ucfirst($order->status->value) }}</span>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-5 py-6 text-center text-sm text-slate-400">No recent orders</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('head-scripts')
<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
@endpush

@push('scripts')
<script>
(function(){
  var dailyRevenue = @json($dailyRevenue);
  var dailyOrders = @json($dailyOrders);
  var paymentBreakdown = @json($paymentBreakdown);

  new ApexCharts(document.getElementById('chartDailyRevenue'), {
    chart: { type: 'area', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
    series: [{ name: 'Revenue', data: dailyRevenue.map(function(d){ return d.total; }) }],
    xaxis: { categories: dailyRevenue.map(function(d){ return d.date; }), labels: { style: { colors: '#94a3b8', fontSize: '11px' } } },
    yaxis: { labels: { formatter: function(v) { return '₦' + (v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'k' : v); }, style: { colors: '#94a3b8', fontSize: '11px' } } },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 } },
    colors: ['#10b981'],
    tooltip: { y: { formatter: function(v) { return '₦' + v.toLocaleString(); } } },
    grid: { borderColor: '#f1f5f9' },
    dataLabels: { enabled: false }
  }).render();

  new ApexCharts(document.getElementById('chartPaymentBreakdown'), {
    chart: { type: 'donut', height: 280, fontFamily: 'inherit' },
    series: paymentBreakdown.map(function(p){ return p.total; }),
    labels: paymentBreakdown.map(function(p){ return p.method; }),
    colors: ['#10b981','#6366f1','#f59e0b','#ef4444','#8b5cf6','#06b6d4'],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: true, formatter: function(v, opts) { return '₦' + (v >= 1e6 ? (v/1e6).toFixed(1)+'M' : (v/1e3).toFixed(0)+'k'); } },
    tooltip: { y: { formatter: function(v) { return '₦' + v.toLocaleString(); } } }
  }).render();

  new ApexCharts(document.getElementById('chartDailyOrders'), {
    chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'inherit' },
    series: [{ name: 'Orders', data: dailyOrders.map(function(d){ return d.count; }) }],
    xaxis: { categories: dailyOrders.map(function(d){ return d.date; }), labels: { style: { colors: '#94a3b8', fontSize: '11px' } } },
    yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '11px' } } },
    colors: ['#6366f1'],
    grid: { borderColor: '#f1f5f9' },
    dataLabels: { enabled: false }
  }).render();
})();
</script>
@endpush
