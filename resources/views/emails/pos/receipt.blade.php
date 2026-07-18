<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
    <div style="background:#16a34a;color:#fff;padding:24px;text-align:center;">
        <h2 style="margin:0;font-size:18px;">Payment Received</h2>
        <p style="margin:4px 0 0;opacity:0.85;font-size:13px;">Thank you for your purchase</p>
    </div>
    <div style="padding:24px;">
        <table width="100%" style="font-size:14px;color:#334155;">
            <tr><td style="color:#94a3b8;padding:4px 0;width:80px">Order</td><td style="font-weight:600;padding:4px 0;">#{{ $order->order_number ?? $order->id }}</td></tr>
            <tr><td style="color:#94a3b8;padding:4px 0;">Store</td><td style="padding:4px 0;">{{ $order->store?->name ?? 'Store' }}</td></tr>
            <tr><td style="color:#94a3b8;padding:4px 0;">Date</td><td style="padding:4px 0;">{{ $order->created_at->format('M d, Y h:i A') }}</td></tr>
        </table>

        <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">

        <table width="100%" style="font-size:13px;color:#475569;border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <th style="text-align:left;padding:6px 0;color:#94a3b8;font-weight:600;font-size:11px;">Item</th>
                    <th style="text-align:center;padding:6px 0;color:#94a3b8;font-weight:600;font-size:11px;">Qty</th>
                    <th style="text-align:right;padding:6px 0;color:#94a3b8;font-weight:600;font-size:11px;">Price</th>
                    <th style="text-align:right;padding:6px 0;color:#94a3b8;font-weight:600;font-size:11px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:6px 0;">{{ $item->product_name }}</td>
                    <td style="text-align:center;padding:6px 0;">{{ $item->quantity }}</td>
                    <td style="text-align:right;padding:6px 0;">₦{{ number_format($item->unit_price, 2) }}</td>
                    <td style="text-align:right;padding:6px 0;font-weight:600;">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0;">

        <table width="100%" style="font-size:14px;">
            <tr>
                <td style="color:#334155;padding:4px 0;">Total</td>
                <td style="text-align:right;font-weight:700;font-size:16px;padding:4px 0;">₦{{ number_format($order->total, 2) }}</td>
            </tr>
        </table>

        @if($order->meta['payment_method'] ?? false)
        <p style="font-size:12px;color:#94a3b8;margin:8px 0 0;text-align:center;">Paid via {{ ucfirst($order->meta['payment_method']) }}</p>
        @endif
    </div>
    <div style="background:#f8fafc;padding:16px 24px;text-align:center;">
        <p style="font-size:11px;color:#94a3b8;margin:0;">{{ config('app.name') }} · POS Receipt</p>
    </div>
</div>
</body>
</html>
