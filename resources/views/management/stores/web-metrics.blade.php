@extends('management.layout')
@section('subtitle', $store->name . ' — Web Metrics')

@section('content')
<div class="flex items-center gap-3 mb-6">
    <h2 class="text-lg font-semibold text-slate-900">{{ $store->name }}</h2>
</div>

{{-- Tab Bar --}}
<div class="flex items-center gap-1 mb-6 border-b border-slate-200">
    <a href="{{ route('management.stores.show', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px transition-colors">Dashboard</a>
    <a href="{{ route('management.stores.web-metrics', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-slate-900 text-slate-900 -mb-px">Web Metrics</a>
    <a href="{{ route('management.stores.settings', $store) }}" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px transition-colors">Settings</a>
</div>

{{-- Metric Cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card
        :value="number_format($storeViews)"
        label="Store Views"
        subtitle="Total page visits"
        icon="fi-rr-eye" />

    <x-management.metric-card
        :value="number_format($productViews)"
        label="Product Views"
        subtitle="Across all products"
        icon="fi-rr-eye" />

    <x-management.metric-card
        :value="$webOrders"
        label="Web Orders"
        subtitle="Orders from storefront"
        icon="fi-rr-shopping-cart" />

    <x-management.metric-card
        :value="'₦' . number_format($webRevenue / 100, 0)"
        label="Web Revenue"
        subtitle="Revenue from online orders"
        icon="fi-rr-chart-histogram" />
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left: Chart + Top Products --}}
    <div class="lg:col-span-2 space-y-6">

        @if(!empty($monthlyWebOrders))
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-slate-800">Web Orders (6 Months)</h3>
            </div>
            <div id="webOrdersChart" style="height: 280px;"></div>
        </div>
        @endif

        {{-- Top Products by Views --}}
        <x-management.card header="Top Products by Views">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($topProducts as $index => $product)
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-semibold shrink-0 {{ $index < 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500' }}">{{ $index + 1 }}</span>
                        <span class="text-sm text-slate-700 truncate">{{ $product->name }}</span>
                    </div>
                    <span class="text-sm font-medium text-slate-500 shrink-0">{{ number_format($product->views) }} views</span>
                </div>
                @empty
                <div class="px-5 py-4 text-center text-sm text-slate-400">No product views recorded</div>
                @endforelse
            </div>
        </x-management.card>
    </div>

    {{-- Right Sidebar --}}
    <div class="space-y-6">

        {{-- Visit Store --}}
        @if($storeUrl)
        <x-management.card>
            <div class="text-center space-y-3">
                <p class="text-sm text-slate-500">Your storefront is live</p>
                <a href="{{ $storeUrl }}" target="_blank" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    <i class="fi fi-rr-globe text-xs"></i> Visit Store
                </a>
                <p class="text-xs text-slate-400 truncate">{{ $storeUrl }}</p>
            </div>
        </x-management.card>
        @endif

        {{-- Recent Activity --}}
        <x-management.card header="Recent Activity">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse($recentActivity as $activity)
                <div class="px-5 py-3">
                    <p class="text-sm text-slate-700">{{ $activity->description }}</p>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="text-xs text-slate-400">{{ $activity->created_at->diffForHumans() }}</span>
                        @if($activity->ip_address)
                        <span class="text-xs text-slate-300">{{ $activity->ip_address }}</span>
                        @endif
                    </div>
                </div>
                @empty
                <div class="px-5 py-4 text-center text-sm text-slate-400">No recent activity</div>
                @endforelse
            </div>
        </x-management.card>

    </div>
</div>

@if(!empty($monthlyWebOrders))
@push('head-scripts')
<script src="{{ asset('vendor_files/assets/vendor/apexcharts/dist/apexcharts.min.js') }}"></script>
@endpush
@push('scripts')
<script>
(function () {
    var options = {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [{ name: 'Web Orders', data: {!! json_encode(array_column($monthlyWebOrders, 'count')) !!} }],
        xaxis: { categories: {!! json_encode(array_column($monthlyWebOrders, 'month')) !!}, labels: { style: { colors: '#94a3b8', fontSize: '12px' } } },
        yaxis: { labels: { style: { colors: '#94a3b8', fontSize: '12px' } } },
        colors: ['#2563eb'],
        plotOptions: { bar: { borderRadius: 4, columnWidth: '50%' } },
        dataLabels: { enabled: false },
        grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
        tooltip: { y: { formatter: function(v) { return v + ' orders'; } } }
    };
    new ApexCharts(document.getElementById('webOrdersChart'), options).render();
})();
</script>
@endpush
@endif
@endsection
