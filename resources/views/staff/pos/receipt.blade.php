<!DOCTYPE html>
<html lang="en">
<head>
    <title>Receipt #{{ $order->order_number }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-slate-100">
<div class="max-w-sm mx-auto py-8 px-4">

    {{-- Receipt Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        {{-- Store Header --}}
        <div class="px-6 py-5 text-center border-b border-dashed border-slate-200">
            <h3 class="text-base font-bold text-slate-800">{{ $store->name }}</h3>
            @if($store->address)<p class="text-xs text-slate-400 mt-0.5">{{ $store->address }}</p>@endif
            <div class="mt-3 space-y-1 text-xs text-slate-500">
                <p><span class="font-mono font-medium text-slate-700">#{{ $order->order_number }}</span></p>
                <p>{{ $order->created_at->format('d M Y, h:i A') }}</p>
            </div>
        </div>

        {{-- Items Table --}}
        <div class="px-5 py-4">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="py-2 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Item</th>
                        <th class="py-2 text-center text-[11px] font-semibold text-slate-400 uppercase tracking-wider w-12">Qty</th>
                        <th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Price</th>
                        <th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($order->items as $item)
                    <tr>
                        <td class="py-2.5 text-sm text-slate-800 font-medium">{{ $item->product_name }}</td>
                        <td class="py-2.5 text-center text-sm text-slate-600">{{ $item->quantity }}</td>
                        <td class="py-2.5 text-right text-sm text-slate-600">₦{{ number_format($item->unit_price, 2) }}</td>
                        <td class="py-2.5 text-right text-sm font-semibold text-slate-800">₦{{ number_format($item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Totals --}}
            <div class="mt-4 pt-4 border-t-2 border-slate-200 space-y-2">
                <div class="flex justify-between text-base font-bold">
                    <span>Total</span>
                    <span>₦{{ number_format($order->total, 2) }}</span>
                </div>
                @if(($order->meta['amount_tendered'] ?? null))
                <div class="flex justify-between text-sm text-slate-500">
                    <span>Amount Tendered</span>
                    <span>₦{{ number_format($order->meta['amount_tendered'], 2) }}</span>
                </div>
                <div class="flex justify-between text-sm font-semibold text-emerald-600">
                    <span>Change</span>
                    <span>₦{{ number_format(max(0, (float)$order->meta['amount_tendered'] - $order->total), 2) }}</span>
                </div>
                @endif
            </div>

            {{-- Meta Info --}}
            <div class="mt-4 pt-4 border-t border-dashed border-slate-200 space-y-2 text-xs">
                <div class="flex justify-between">
                    <span class="text-slate-400">Payment Method</span>
                    <span class="font-medium text-slate-700 capitalize">{{ $order->meta['payment_method'] ?? '—' }}</span>
                </div>
                @if(($order->meta['customer_name'] ?? null))
                <div class="flex justify-between">
                    <span class="text-slate-400">Customer</span>
                    <span class="font-medium text-slate-700">{{ $order->meta['customer_name'] }}</span>
                </div>
                @endif
                @if(($order->meta['customer_phone'] ?? null))
                <div class="flex justify-between">
                    <span class="text-slate-400">Phone</span>
                    <span class="font-medium text-slate-700">{{ $order->meta['customer_phone'] }}</span>
                </div>
                @endif
                @php $tx = $order->transactions->first(); @endphp
                @if($tx)
                <div class="flex justify-between">
                    <span class="text-slate-400">Reference</span>
                    <span class="font-mono font-medium text-slate-700 text-[11px]">{{ $tx->reference }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 text-center">
            <p class="text-xs text-slate-400">Thank you for your purchase!</p>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="flex items-center gap-3 mt-4 no-print">
        <button onclick="window.print()" class="flex-1 py-3 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-colors shadow-sm">
            <i class="fi fi-rr-print mr-1.5"></i> Print Receipt
        </button>
        <a href="{{ route('pos.index') }}" class="flex-1 py-3 border border-slate-200 bg-white text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors text-center shadow-sm">
            <i class="fi fi-rr-plus mr-1.5"></i> New Sale
        </a>
    </div>
</div>

<script>setTimeout(()=>window.print(),400);</script>
</body>
</html>
