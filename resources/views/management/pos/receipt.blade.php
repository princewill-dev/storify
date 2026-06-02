@extends('management.layout')
@section('subtitle', 'Receipt #' . $order->order_number)

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('management.pos.terminal', $store) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
        <i class="fi fi-rr-arrow-left text-xs"></i> Back to Terminal
    </a>
    <div class="flex-1"></div>
    @php $tx = $order->transactions->first(); @endphp
    @if($tx)
    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold {{ $tx->status === 'confirmed' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20' }}">
        {{ $tx->status === 'confirmed' ? 'Paid' : 'Pending' }}
    </span>
    @endif
</div>

<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-5 text-center border-b border-slate-100">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">{{ $store->name }}</p>
            <h2 class="text-lg font-bold text-slate-800">Receipt #{{ $order->order_number }}</h2>
            <p class="text-xs text-slate-400 mt-0.5">{{ $order->created_at->format('M d, Y h:i A') }}</p>
        </div>

        <div class="p-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="py-2 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Item</th>
                        <th class="py-2 text-center text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Qty</th>
                        <th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Price</th>
                        <th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($order->items as $item)
                    <tr>
                        <td class="py-2 text-sm text-slate-800">{{ $item->product_name }}</td>
                        <td class="py-2 text-center text-sm text-slate-600">{{ $item->quantity }}</td>
                        <td class="py-2 text-right text-sm text-slate-600">₦{{ number_format($item->unit_price, 2) }}</td>
                        <td class="py-2 text-right text-sm font-semibold text-slate-800">₦{{ number_format($item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-200">
                        <td colspan="3" class="py-3 text-sm font-semibold text-slate-800 text-right">Total</td>
                        <td class="py-3 text-right text-lg font-bold text-slate-900">₦{{ number_format($order->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>

            <div class="mt-4 pt-4 border-t border-slate-100 space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-slate-500">Payment Method</span>
                    <span class="font-medium text-slate-700 capitalize">{{ $order->meta['payment_method'] ?? '—' }}</span>
                </div>
                @if($tx)
                <div class="flex justify-between">
                    <span class="text-slate-500">Transaction Ref</span>
                    <span class="font-mono text-xs text-slate-600">{{ $tx->reference }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Status</span>
                    <span class="font-semibold {{ $tx->status === 'confirmed' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($tx->status) }}</span>
                </div>
                @endif
                @if(($order->meta['customer_name'] ?? null) || ($order->meta['customer_phone'] ?? null))
                <div class="flex justify-between">
                    <span class="text-slate-500">Customer</span>
                    <span class="font-medium text-slate-700">{{ $order->meta['customer_name'] ?? '' }} {{ $order->meta['customer_phone'] ?? '' }}</span>
                </div>
                @endif
            </div>

            @if(($order->meta['payment_method'] ?? '') === 'transfer')
            <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-100 text-sm text-amber-700">
                <p class="font-semibold mb-1">Awaiting Bank Transfer</p>
                <p>This order will be confirmed once the payment slip is verified.</p>
            </div>
            @endif
        </div>

        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 text-center">
            <p class="text-xs text-slate-400">{{ $store->name }} · POS Terminal</p>
        </div>
    </div>

    <a href="{{ route('management.pos.terminal', $store) }}" class="block w-full mt-4 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors text-center">New Sale</a>
</div>
@endsection
