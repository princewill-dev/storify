@extends('admin.layout')
@section('subtitle', 'Executive Dashboard')

@push('styles')
<style>
    .kpi-card { transition: transform 0.15s; }
    .kpi-card:hover { transform: translateY(-2px); }
</style>
@endpush

@section('content')
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
        <h2 class="text-lg font-semibold text-slate-800">Executive Dashboard</h2>
        <p class="text-sm text-slate-400 mt-0.5">Daily sales, stock movements, and branch performance overview</p>
    </div>
    <form method="GET" class="flex items-center gap-2 flex-wrap">
        <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500 py-1.5" placeholder="From">
        <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500 py-1.5" placeholder="To">
        <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500 py-1.5">
            <option value="">All Branches</option>
            @foreach($allStores as $s)
            <option value="{{ $s->id }}" {{ $filterStoreId == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
        @if(request('from') || request('to') || $filterStoreId)
        <a href="{{ route('admin.executive') }}" class="text-xs text-slate-400 hover:text-slate-600">Clear Filters</a>
        @endif
    </form>
</div>

{{-- Top KPI Row --}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Today's Revenue</p>
        <p class="text-xl font-bold text-emerald-600 mt-1">₦{{ number_format($todaySales, 0) }}</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Today's Orders</p>
        <p class="text-xl font-bold text-blue-600 mt-1">{{ number_format($todayOrders) }}</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Stock Value</p>
        <p class="text-xl font-bold text-indigo-600 mt-1">₦{{ number_format($stockValue, 0) }}</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Low Stock</p>
        <p class="text-xl font-bold text-amber-600 mt-1">{{ $lowStockCount }}<span class="text-xs text-slate-400 font-normal ml-1">/ {{ $outOfStockCount }} OOS</span></p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Active Stores</p>
        <p class="text-xl font-bold text-violet-600 mt-1">{{ $activeStores }}</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Open POS</p>
        <p class="text-xl font-bold text-teal-600 mt-1">{{ $openPosSessions }}</p>
    </div>
</div>

{{-- Second Row --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Revenue (MTD)</p>
        <p class="text-lg font-bold text-slate-800 mt-1">₦{{ number_format($mtdRevenue, 0) }}</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Customers</p>
        <p class="text-lg font-bold text-slate-800 mt-1">{{ number_format($totalCustomers) }}<span class="text-xs text-slate-400 font-normal ml-1">+{{ $newCustomersThisMonth }} new</span></p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Stock IN Today</p>
        <p class="text-lg font-bold text-emerald-600 mt-1">+{{ number_format($stockInToday) }} units</p>
    </div>
    <div class="kpi-card bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Stock OUT Today</p>
        <p class="text-lg font-bold text-red-500 mt-1">−{{ number_format($stockOutToday) }} units</p>
    </div>
</div>

{{-- Branch Performance Table --}}
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-800">Branch Performance</h3>
        <span class="text-xs text-slate-400">{{ $stores->count() }} branches</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-slate-100">
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Branch</th>
                <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase">Revenue Today</th>
                <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase hidden sm:table-cell">Revenue MTD</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">Orders Today</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase hidden md:table-cell">Products</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase">POS</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase hidden lg:table-cell">Last Sale</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($stores as $store)
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-5 py-3"><span class="text-sm font-medium text-slate-800">{{ $store->name }}</span>@if($store->user)<p class="text-[11px] text-slate-400">{{ $store->user->name }}</p>@endif</td>
                    <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-emerald-600">₦{{ number_format($store->revenue_today ?? 0, 0) }}</span></td>
                    <td class="px-5 py-3 text-right hidden sm:table-cell"><span class="text-sm text-slate-700">₦{{ number_format($store->revenue_mtd ?? 0, 0) }}</span></td>
                    <td class="px-5 py-3 text-center"><span class="text-sm font-medium text-slate-700">{{ $store->orders_today ?? 0 }}</span></td>
                    <td class="px-5 py-3 text-center hidden md:table-cell"><span class="text-sm text-slate-600">{{ $store->products_count }}</span></td>
                    <td class="px-5 py-3 text-center">@if($store->pos_status === 'open')<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700"><span class="w-1 h-1 rounded-full bg-emerald-500"></span> Live</span>@else<span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">Offline</span>@endif</td>
                    <td class="px-5 py-3 hidden lg:table-cell"><span class="text-xs text-slate-400">{{ $store->last_order_at ? $store->last_order_at->diffForHumans() : '—' }}</span></td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-400">No branch data available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Charts Row 1: Revenue + Orders --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Daily Revenue (30 Days)</h3>
        <div id="revenueChart" style="height: 260px;"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Daily Orders (30 Days)</h3>
        <div id="ordersChart" style="height: 260px;"></div>
    </div>
</div>

{{-- Charts Row 2: Stock Movement + Payment Methods --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Daily Stock Movement</h3>
        <div id="stockChart" style="height: 260px;"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Payment Method Breakdown</h3>
        <div id="paymentChart" style="height: 260px;"></div>
    </div>
</div>

{{-- Stock Transfers Summary --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Stock Transfers</h3>
            <span class="text-xs text-slate-400">{{ $transferStats['pending'] }} pending</span>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="text-center p-3 bg-amber-50 rounded-lg"><p class="text-[10px] text-amber-600 uppercase font-semibold">Pending</p><p class="text-lg font-bold text-amber-700">{{ $transferStats['pending'] }}</p></div>
                <div class="text-center p-3 bg-blue-50 rounded-lg"><p class="text-[10px] text-blue-600 uppercase font-semibold">Dispatched Today</p><p class="text-lg font-bold text-blue-700">{{ $transferStats['today_dispatched'] }}</p></div>
                <div class="text-center p-3 bg-emerald-50 rounded-lg"><p class="text-[10px] text-emerald-600 uppercase font-semibold">Received Today</p><p class="text-lg font-bold text-emerald-700">{{ $transferStats['today_received'] }}</p></div>
            </div>
            @if($pendingTransfers->isNotEmpty())
            <div class="space-y-2">
                @foreach($pendingTransfers as $pt)
                <div class="flex items-center justify-between text-xs py-1.5 border-b border-slate-50 last:border-0">
                    <div class="min-w-0"><span class="font-medium text-slate-700">{{ $pt->fromLocation?->name ?? '—' }}</span> <span class="text-slate-400">→</span> <span class="font-medium text-slate-700">{{ $pt->toLocation?->name ?? '—' }}</span></div>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $pt->status === 'approved' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }}">{{ ucfirst($pt->status) }}</span>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-xs text-slate-400 text-center py-4">No pending transfers.</p>
            @endif
        </div>
    </div>

    {{-- Recent Transactions --}}
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Recent Transactions</h3></div>
        <div class="divide-y divide-slate-50">
            @foreach($recentTransactions as $tx)
            <div class="px-5 py-3 flex items-center justify-between text-sm hover:bg-slate-50/50">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-slate-800 truncate">{{ $tx->order?->store?->name ?? '—' }}</p>
                    <p class="text-[10px] text-slate-400 font-mono truncate">{{ $tx->reference }}</p>
                </div>
                <div class="text-right shrink-0 ml-3">
                    <p class="text-sm font-semibold text-slate-800">₦{{ number_format($tx->amount, 0) }}</p>
                    <p class="text-[10px] text-slate-400">{{ $tx->paymentMethod?->name ?? '—' }} · {{ $tx->created_at->format('h:i A') }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const baseOpts = {
        chart: { toolbar: { show: false }, fontFamily: 'inherit', height: 260 },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { type: 'datetime', labels: { style: { colors: '#94a3b8', fontSize: '10px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
        tooltip: { x: { format: 'MMM dd' } },
    };

    const revenueData = @json($dailyRevenue->values());
    const ordersData = @json($dailyOrders->values());
    const stockInData = @json($dailyStockIn->values());
    const stockOutData = @json($dailyStockOut->values());
    const paymentData = @json($paymentBreakdown->values());

    new ApexCharts(document.getElementById('revenueChart'), {
        ...baseOpts, chart: { ...baseOpts.chart, type: 'area' },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 } },
        series: [{ name: 'Revenue', data: revenueData.map(r => ({ x: r.date, y: r.total })) }],
        colors: ['#10b981'],
        tooltip: { ...baseOpts.tooltip, y: { formatter: v => '₦' + v.toLocaleString() } }
    }).render();

    new ApexCharts(document.getElementById('ordersChart'), {
        ...baseOpts, chart: { ...baseOpts.chart, type: 'bar' },
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 3 } },
        series: [{ name: 'Orders', data: ordersData.map(r => ({ x: r.date, y: r.count })) }],
        colors: ['#6366f1'],
        tooltip: { ...baseOpts.tooltip, y: { formatter: v => v } }
    }).render();

    new ApexCharts(document.getElementById('stockChart'), {
        ...baseOpts, chart: { ...baseOpts.chart, type: 'bar', stacked: false },
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 3 } },
        series: [
            { name: 'Stock IN', data: stockInData.map(r => ({ x: r.date, y: r.total })), color: '#10b981' },
            { name: 'Stock OUT', data: stockOutData.map(r => ({ x: r.date, y: r.total })), color: '#ef4444' },
        ],
        tooltip: baseOpts.tooltip,
    }).render();

    if (paymentData.length > 0) {
        new ApexCharts(document.getElementById('paymentChart'), {
            ...baseOpts, chart: { ...baseOpts.chart, type: 'donut' },
            series: paymentData.map(p => p.total),
            labels: paymentData.map(p => p.method + ' (' + p.count + ' orders)'),
            colors: ['#10b981', '#6366f1', '#f59e0b', '#94a3b8'],
            stroke: { width: 0 },
            plotOptions: { pie: { donut: { size: '55%' } } },
            tooltip: { y: { formatter: v => '₦' + v.toLocaleString() } },
            legend: { fontSize: '12px', labels: { colors: '#64748b' } },
        }).render();
    }
});
</script>
@endpush
