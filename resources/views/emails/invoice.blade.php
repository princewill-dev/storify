<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
    <div style="background:#0f172a;color:#fff;padding:24px;text-align:left;">
        <h2 style="margin:0;font-size:17px;">Invoice {{ $invoice->invoice_number }}</h2>
        <p style="margin:4px 0 0;opacity:0.7;font-size:13px;">From {{ $invoice->store?->name ?? config('app.name') }}</p>
    </div>
    <div style="padding:24px;">
        <table width="100%" style="font-size:13px;color:#334155;margin-bottom:16px;">
            <tr><td style="color:#94a3b8;width:60px;padding:3px 0;">Issued</td><td>{{ $invoice->issue_date->format('M d, Y') }}</td></tr>
            <tr><td style="color:#94a3b8;padding:3px 0;">Due</td><td style="font-weight:600;">{{ $invoice->due_date->format('M d, Y') }}</td></tr>
            <tr><td style="color:#94a3b8;padding:3px 0;">To</td><td>{{ $invoice->recipient_name ?? $invoice->customer?->full_name }}</td></tr>
        </table>

        <table width="100%" style="font-size:12px;border-collapse:collapse;margin-bottom:16px;">
            <thead>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <th style="text-align:left;padding:6px 0;color:#94a3b8;font-weight:600;">Description</th>
                    <th style="text-align:center;padding:6px 0;color:#94a3b8;font-weight:600;">Qty</th>
                    <th style="text-align:right;padding:6px 0;color:#94a3b8;font-weight:600;">Price</th>
                    <th style="text-align:right;padding:6px 0;color:#94a3b8;font-weight:600;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:6px 0;color:#0f172a;">{{ $item->description }}</td>
                    <td style="text-align:center;padding:6px 0;color:#475569;">{{ $item->quantity }}</td>
                    <td style="text-align:right;padding:6px 0;color:#475569;">₦{{ number_format($item->unit_price, 2) }}</td>
                    <td style="text-align:right;padding:6px 0;color:#0f172a;font-weight:600;">₦{{ number_format($item->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <table width="100%" style="font-size:13px;border-top:1px solid #e2e8f0;padding-top:12px;">
            <tr><td style="color:#475569;">Subtotal</td><td style="text-align:right;color:#0f172a;">₦{{ number_format($invoice->subtotal, 2) }}</td></tr>
            @if($invoice->tax_rate > 0)
            <tr><td style="color:#475569;">Tax ({{ number_format($invoice->tax_rate, 1) }}%)</td><td style="text-align:right;color:#0f172a;">₦{{ number_format($invoice->tax_amount, 2) }}</td></tr>
            @endif
            <tr style="font-size:15px;font-weight:700;border-top:1px solid #e2e8f0;"><td style="color:#0f172a;padding-top:8px;">Total</td><td style="text-align:right;color:#0f172a;padding-top:8px;">₦{{ number_format($invoice->total, 2) }}</td></tr>
        </table>

        @if($invoice->terms)
        <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;">{{ $invoice->terms }}</div>
        @endif

        @if($paymentUrl)
        <div style="text-align:center;margin-top:20px;">
            <a href="{{ $paymentUrl }}" style="display:inline-block;padding:14px 40px;background:#0f172a;color:#fff;text-decoration:none;border-radius:10px;font-size:14px;font-weight:600;">Pay Invoice Now</a>
        </div>
        @endif
    </div>
</div>
</body>
</html>
