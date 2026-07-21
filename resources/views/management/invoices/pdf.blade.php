<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Invoice {{ $invoice->invoice_number }}</title>
<style>
    body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a1a; margin: 0; padding: 40px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
    .header h1 { font-size: 28px; font-weight: 700; color: #0f172a; margin: 0; }
    .company h2 { font-size: 16px; margin: 0 0 4px; }
    .company p { font-size: 11px; color: #64748b; margin: 0 0 2px; }
    .meta { text-align: right; }
    .meta .badge { display: inline-block; padding: 4px 12px; border-radius: 50px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
    .badge-draft { background: #f1f5f9; color: #475569; }
    .badge-sent { background: #eff6ff; color: #2563eb; }
    .badge-paid { background: #ecfdf5; color: #059669; }
    .badge-overdue { background: #fef2f2; color: #dc2626; }
    .badge-void { background: #f1f5f9; color: #94a3b8; }
    .info-row { display: flex; gap: 60px; margin-bottom: 30px; }
    .info-block h3 { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin: 0 0 6px; }
    .info-block p { margin: 0 0 2px; font-size: 12px; }
    .dates { text-align: right; flex: 1; font-size: 12px; }
    .dates div { margin-bottom: 4px; }
    .dates span { color: #64748b; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    th { text-align: left; padding: 8px 0; border-bottom: 2px solid #e2e8f0; font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
    th.right, td.right { text-align: right; }
    th.center { text-align: center; }
    td { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .totals { margin-left: auto; width: 220px; }
    .totals div { display: flex; justify-content: space-between; padding: 4px 0; font-size: 12px; }
    .totals .grand { font-size: 15px; font-weight: 700; border-top: 2px solid #0f172a; padding-top: 8px; margin-top: 4px; }
    .terms { margin-top: 24px; padding: 12px; background: #f8fafc; font-size: 11px; color: #64748b; border-radius: 6px; }
    .footer { margin-top: 60px; text-align: center; font-size: 10px; color: #94a3b8; }
</style>
</head>
<body>
<div class="header">
    <div class="company">
        @if($invoice->store?->logo_path)
        <img src="{{ public_path('storage/' . $invoice->store->logo_path) }}" style="height:36px;margin-bottom:8px;">
        @endif
        <h2>{{ $invoice->store?->name ?? config('app.name') }}</h2>
        <p>{{ $invoice->store?->address ?? '' }}</p>
    </div>
    <div class="meta">
        <h1>INVOICE</h1>
        <p style="font-size:12px;color:#64748b;">{{ $invoice->invoice_number }}</p>
        <span class="badge badge-{{ $invoice->status->value }}">{{ $invoice->status->label() }}</span>
    </div>
</div>

<div class="info-row">
    <div class="info-block">
        <h3>Bill To</h3>
        <p><strong>{{ $invoice->recipient_name ?? $invoice->customer?->full_name ?? '—' }}</strong></p>
        @if($invoice->recipient_email)<p>{{ $invoice->recipient_email }}</p>@endif
        @if($invoice->recipient_phone)<p>{{ $invoice->recipient_phone }}</p>@endif
        @if($invoice->recipient_address)<p>{{ $invoice->recipient_address }}</p>@endif
    </div>
    <div class="dates">
        <div><span>Issue Date:</span> {{ $invoice->issue_date->format('M d, Y') }}</div>
        <div><span>Due Date:</span> <strong>{{ $invoice->due_date->format('M d, Y') }}</strong></div>
    </div>
</div>

<table>
    <thead>
        <tr><th>Description</th><th class="center">Qty</th><th class="right">Unit Price</th><th class="right">Amount</th></tr>
    </thead>
    <tbody>
        @foreach($invoice->items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td class="center">{{ $item->quantity }}</td>
            <td class="right">₦{{ number_format($item->unit_price, 2) }}</td>
            <td class="right"><strong>₦{{ number_format($item->amount, 2) }}</strong></td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="totals">
    <div><span>Subtotal</span><span>₦{{ number_format($invoice->subtotal, 2) }}</span></div>
    @if($invoice->tax_rate > 0)
    <div><span>Tax ({{ number_format($invoice->tax_rate, 1) }}%)</span><span>₦{{ number_format($invoice->tax_amount, 2) }}</span></div>
    @endif
    @if($invoice->discount_value > 0)
    <div><span>Discount</span><span style="color:#dc2626;">−₦{{ number_format($invoice->discount_value, 2) }}</span></div>
    @endif
    <div class="grand"><span>Total</span><span>₦{{ number_format($invoice->total, 2) }}</span></div>
</div>

@if($invoice->terms)
<div class="terms">{{ $invoice->terms }}</div>
@endif

<div class="footer">{{ config('app.name') }} · Invoice {{ $invoice->invoice_number }}</div>
</body>
</html>
