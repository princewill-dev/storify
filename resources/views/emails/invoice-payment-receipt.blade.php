<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
    <div style="background:#16a34a;color:#fff;padding:24px;text-align:center;">
        <h2 style="margin:0;font-size:17px;">Payment Received</h2>
        <p style="margin:4px 0 0;opacity:0.85;font-size:13px;">Thank you for your payment</p>
    </div>
    <div style="padding:24px;">
        <table width="100%" style="font-size:13px;color:#334155;margin-bottom:16px;">
            <tr><td style="color:#94a3b8;width:80px;padding:3px 0;">Invoice</td><td style="font-weight:600;">{{ $invoice->invoice_number }}</td></tr>
            <tr><td style="color:#94a3b8;padding:3px 0;">Amount Paid</td><td style="font-weight:700;color:#16a34a;">₦{{ number_format($transaction->amount, 2) }}</td></tr>
            <tr><td style="color:#94a3b8;padding:3px 0;">Reference</td><td style="font-size:12px;color:#64748b;">{{ $transaction->reference }}</td></tr>
            <tr><td style="color:#94a3b8;padding:3px 0;">Date</td><td>{{ $transaction->created_at->format('M d, Y h:i A') }}</td></tr>
        </table>

        <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;text-align:center;">
            <p style="margin:0;font-size:12px;color:#64748b;">Total Invoice: <strong>₦{{ number_format($invoice->total, 2) }}</strong></p>
            <p style="margin:4px 0 0;font-size:12px;color:#64748b;">Total Paid: <strong>₦{{ number_format($invoice->amount_paid, 2) }}</strong></p>
            @if($invoice->remainingBalance() > 0)
            <p style="margin:4px 0 0;font-size:12px;color:#f59e0b;">Remaining: <strong>₦{{ number_format($invoice->remainingBalance(), 2) }}</strong></p>
            @endif
        </div>
    </div>
    <div style="background:#f8fafc;padding:16px 24px;text-align:center;">
        <p style="font-size:11px;color:#94a3b8;margin:0;">{{ config('app.name') }} · Payment Receipt</p>
    </div>
</div>
</body>
</html>
