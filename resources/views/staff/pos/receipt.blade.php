<!DOCTYPE html>
<html lang="en">
<head>
    <title>Receipt #{{ $order->order_number }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    <style>@media print { .no-print { display: none !important; } }</style>
</head>
<body class="bg-slate-50">
<div class="max-w-sm mx-auto py-6 px-4">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-5 text-center border-b border-slate-100">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">{{ $store->name }}</p>
            <h2 class="text-lg font-bold text-slate-800">Receipt #{{ $order->order_number }}</h2>
            <p class="text-xs text-slate-400 mt-0.5">{{ $order->created_at->format('M d, Y h:i A') }}</p>
        </div>
        <div class="p-5">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-100"><th class="py-2 text-left text-[11px] font-semibold text-slate-400 uppercase">Item</th><th class="py-2 text-center text-[11px] font-semibold text-slate-400 uppercase">Qty</th><th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase">Price</th><th class="py-2 text-right text-[11px] font-semibold text-slate-400 uppercase">Subtotal</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($order->items as $item)
                    <tr><td class="py-2 text-sm text-slate-800">{{ $item->product_name }}</td><td class="py-2 text-center text-sm text-slate-600">{{ $item->quantity }}</td><td class="py-2 text-right text-sm text-slate-600">₦{{ number_format($item->unit_price, 2) }}</td><td class="py-2 text-right text-sm font-semibold">₦{{ number_format($item->subtotal, 2) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr class="border-t-2 border-slate-200"><td colspan="3" class="py-3 text-sm font-semibold text-right">Total</td><td class="py-3 text-right text-lg font-bold">₦{{ number_format($order->total, 2) }}</td></tr></tfoot>
            </table>
            <div class="mt-4 pt-4 border-t border-slate-100 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Payment</span><span class="font-medium capitalize">{{ $order->meta['payment_method'] ?? '—' }}</span></div>
                @if(($order->meta['customer_name'] ?? null))<div class="flex justify-between"><span class="text-slate-500">Customer</span><span class="font-medium">{{ $order->meta['customer_name'] }}</span></div>@endif
                @if(($order->meta['amount_tendered'] ?? null))<div class="flex justify-between"><span class="text-slate-500">Tendered</span><span class="font-medium">₦{{ number_format($order->meta['amount_tendered'], 2) }}</span></div><div class="flex justify-between"><span class="text-slate-500">Change</span><span class="font-bold text-emerald-600">₦{{ number_format(max(0, (float)$order->meta['amount_tendered'] - $order->total), 2) }}</span></div>@endif
            </div>
        </div>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 text-center"><p class="text-xs text-slate-400">Thank you for your purchase!</p></div>
    </div>
    <div class="flex items-center gap-3 mt-4 no-print">
        <button onclick="window.print()" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Print</button>
        <a href="{{ route('pos.index') }}" class="flex-1 py-2.5 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 text-center">New Sale</a>
    </div>
</div>
</body>
</html>
