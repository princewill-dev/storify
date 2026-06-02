@extends('management.layout')
@section('subtitle', $session->session_code)

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('management.pos.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
        <i class="fi fi-rr-arrow-left text-xs"></i> Sessions
    </a>
    <div class="h-4 w-px bg-slate-200"></div>
    <div>
        <span class="text-sm font-semibold text-slate-800">{{ $session->store->name }}</span>
        <span class="mx-1.5 text-slate-300">·</span>
        <span class="text-xs text-slate-500">{{ $session->session_code }}</span>
    </div>
    <div class="flex-1"></div>
    @if($session->isOpen())
    <a href="{{ route('management.pos.terminal', $session->store) }}" class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors">Open Terminal</a>
    @else
    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-500/20">Closed</span>
    @endif
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <x-management.metric-card label="Opening Float" value="₦{{ number_format($session->opening_balance / 100, 2) }}" icon="fi fi-rr-money-bill-wave" />
    <x-management.metric-card label="Total Sales" value="₦{{ number_format($totalSales, 2) }}" icon="fi fi-rr-chart-histogram" />
    <x-management.metric-card label="Orders" :value="$orderCount" icon="fi fi-rr-receipt" />
    @if($session->difference !== null)
    <x-management.metric-card label="Difference" value="{{ $session->difference >= 0 ? '+' : '' }}₦{{ number_format(abs($session->difference) / 100, 2) }}" icon="fi fi-rr-balance-scale-right" />
    @else
    <x-management.metric-card label="Expected Close" value="₦{{ number_format(($session->opening_balance + ($totalSales * 100)) / 100, 2) }}" icon="fi fi-rr-balance-scale-right" />
    @endif
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800">Sales Records</h3>
                <span class="text-xs text-slate-400">{{ $orderCount }} orders</span>
            </div>
            @if($orders->isEmpty())
            <div class="px-5 py-12 text-center text-sm text-slate-400">No orders in this session.</div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Order</th>
                            <th class="px-5 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Items</th>
                            <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Payment</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($orders as $order)
                        @php $tx = $order->transactions->first(); @endphp
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3"><span class="text-sm font-medium text-slate-800">#{{ $order->order_number ?? $order->id }}</span></td>
                            <td class="px-5 py-3 text-center hidden sm:table-cell"><span class="text-sm text-slate-600">{{ $order->items->count() }}</span></td>
                            <td class="px-5 py-3 text-right"><span class="text-sm font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span></td>
                            <td class="px-5 py-3 hidden sm:table-cell">
                                @if($tx && $tx->status === 'confirmed') <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">Paid</span>
                                @elseif($tx) <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">Pending</span>
                                @else <span class="text-xs text-slate-400">—</span> @endif
                            </td>
                            <td class="px-5 py-3 hidden md:table-cell"><span class="text-xs text-slate-400">{{ $order->created_at->format('h:i A') }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Session Info</h3></div>
            <div class="p-5 space-y-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Staff</span><span class="font-medium text-slate-700">{{ $session->staff?->name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Opened</span><span class="text-slate-700">{{ $session->opened_at->format('M d, h:i A') }}</span></div>
                @if($session->closed_at)
                <div class="flex justify-between"><span class="text-slate-500">Closed</span><span class="text-slate-700">{{ $session->closed_at->format('M d, h:i A') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Duration</span><span class="text-slate-700">{{ $session->opened_at->diffForHumans($session->closed_at, true) }}</span></div>
                @endif
                @if($session->closing_balance_actual !== null)
                <div class="flex justify-between"><span class="text-slate-500">Actual Close</span><span class="font-semibold text-slate-800">₦{{ number_format($session->closing_balance_actual / 100, 2) }}</span></div>
                @endif
                @if($session->notes)
                <div class="pt-2 border-t border-slate-100"><p class="text-slate-400 text-xs mb-1">Notes</p><p class="text-slate-600">{{ $session->notes }}</p></div>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100"><h3 class="text-sm font-semibold text-slate-800">Staff Activity</h3></div>
            <div class="divide-y divide-slate-50">
                @foreach($staffSessions as $ss)
                <a href="{{ route('management.pos.show', $ss) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50/50 transition-colors">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-slate-700 truncate">{{ $ss->store->name }}</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">{{ $ss->opened_at->format('M d, h:i A') }} @if($ss->closed_at) → {{ $ss->closed_at->format('h:i A') }} @endif</p>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium {{ $ss->status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ ucfirst($ss->status) }}</span>
                </a>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
