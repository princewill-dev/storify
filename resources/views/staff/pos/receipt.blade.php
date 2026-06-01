<!DOCTYPE html>
<html lang="en">
<head>
    <title>Sale Receipt</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
        body { background: #f8f9fa; }
        .receipt { max-width: 350px; margin: 20px auto; }
        .receipt-card { background: #fff; border: 1px solid #dee2e6; padding: 20px; }
    </style>
</head>
<body>
<div class="receipt no-print mt-3">
    <button class="btn btn-primary w-100 mb-3" onclick="window.print()">Print Receipt</button>
    <a href="{{ route('staff.pos') }}" class="btn btn-outline-secondary w-100">New Sale</a>
</div>

<div class="receipt">
    <div class="receipt-card">
        <div class="text-center mb-3">
            <h5 class="mb-0">{{ $store->name }}</h5>
            <small class="text-muted">{{ $store->physical_address ?? $store->address ?? '' }}</small>
        </div>
        <hr>
        <div class="mb-2">
            <small>
                <strong>Order:</strong> {{ $order->order_number }}<br>
                <strong>Date:</strong> {{ $order->created_at->format('d M Y H:i') }}<br>
                @if($order->meta['customer_name'] ?? false)
                    <strong>Customer:</strong> {{ $order->meta['customer_name'] }}<br>
                @endif
                <strong>Payment:</strong> {{ ucfirst($order->meta['payment_method'] ?? 'N/A') }}
            </small>
        </div>
        <hr>
        <table class="w-100 mb-2" style="font-size: 0.9rem;">
            <thead>
                <tr class="border-bottom">
                    <th>Item</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Price</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-end">₦{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-end">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <hr>
        <div class="d-flex justify-content-between fw-bold fs-5">
            <span>TOTAL:</span>
            <span>₦{{ number_format($order->total, 2) }}</span>
        </div>
        @php
            $tendered = $order->meta['amount_tendered'] ?? null;
        @endphp
        @if($tendered)
            <div class="mt-2" style="font-size: 0.9rem;">
                <div class="d-flex justify-content-between">
                    <span>Amount Tendered:</span>
                    <span>₦{{ number_format($tendered, 2) }}</span>
                </div>
                <div class="d-flex justify-content-between text-success fw-bold">
                    <span>Change:</span>
                    <span>₦{{ number_format(max(0, (int)$tendered - $order->total), 2) }}</span>
                </div>
            </div>
        @endif
        <div class="text-center mt-3">
            <small class="text-muted">Thank you for your purchase!</small>
        </div>
    </div>
</div>
</body>
</html>
