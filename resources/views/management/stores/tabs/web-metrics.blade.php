<div class="space-y-6">
    {{-- Metric Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Store Views</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ number_format($storeViews) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Product Views</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ number_format($productViews) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Web Orders</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ number_format($webOrders) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Web Revenue</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">₦{{ number_format($webRevenue, 0) }}</p>
        </div>
    </div>

    {{-- Chart --}}
    @if(!empty($monthlyWebOrders))
    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <h3 class="text-sm font-semibold text-slate-800 mb-4">Web Orders (6 Months)</h3>
        <div id="storeWebOrdersChart" style="height: 280px;"></div>
    </div>
    @endif

    {{-- Top Products --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Top Products by Views</h3>
        </div>
        <div class="divide-y divide-slate-50">
            @forelse($topProducts as $p)
            <a href="{{ route('management.products.show', $p) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                <span class="text-sm font-medium text-slate-800 truncate">{{ $p->name }}</span>
                <span class="text-xs text-slate-400">{{ number_format($p->views) }} views</span>
            </a>
            @empty
            <div class="px-5 py-6 text-center text-sm text-slate-400">No product view data yet</div>
            @endforelse
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
<script>
(function () {
    var el = document.getElementById('storeWebOrdersChart');
    if (!el) return;
    var options = {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [{ name: 'Orders', data: @js(array_column($monthlyWebOrders, 'count')) }],
        xaxis: { categories: @js(array_column($monthlyWebOrders, 'month')), labels: { style: { colors: '#94a3b8', fontSize: '12px' } } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '12px' } } },
        colors: ['#2563eb'],
        plotOptions: { bar: { borderRadius: 6, columnWidth: '50%' } },
        dataLabels: { enabled: false },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    };
    new ApexCharts(el, options).render();
})();
</script>
@endpush
