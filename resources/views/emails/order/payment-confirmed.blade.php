<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 8px 0 0; opacity: 0.9; font-size: 14px; }
        .content { background: #ffffff; padding: 30px 24px; }
        .order-details { background: #f9fafb; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #e5e7eb; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #6b7280; font-size: 13px; }
        .detail-value { color: #111827; font-size: 14px; font-weight: 500; }
        .amount { font-size: 28px; font-weight: 700; color: #059669; margin: 15px 0; text-align: center; }
        .success-icon { font-size: 48px; color: #059669; margin-bottom: 12px; }
        .button { display: inline-block; padding: 12px 28px; background: #059669; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: 600; font-size: 14px; }
        .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e7eb; }
        .note { background: #ecfdf5; border-left: 4px solid #059669; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; }
        .meta { font-size: 12px; color: #9ca3af; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="success-icon">&#10003;</div>
            <h1>Payment Confirmed</h1>
            <p>Payment has been verified and approved</p>
        </div>

        <div class="content">
            <p>Hello <strong>{{ $recipient?->name ?? $recipient->first_name ?? 'Admin' }}</strong>,</p>

            @if($recipient instanceof \App\Models\Customer)
                <p>Great news! <strong>{{ $store->name }}</strong> has confirmed your payment for order <strong>{{ $order->order_number }}</strong>.</p>
            @else
                <p>A payment has been confirmed for <strong>{{ $store->name }}</strong>. Here are the details:</p>
            @endif

            <div class="order-details">
                <h3 style="margin-top: 0; color: #059669; font-size: 16px;">Payment Details</h3>

                <div class="detail-row">
                    <span class="detail-label">Order Number</span>
                    <span class="detail-value">{{ $order->order_number }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Transaction Reference</span>
                    <span class="detail-value" style="font-family: monospace; font-size: 13px;">{{ $transaction->reference }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <span class="detail-value">{{ $transaction->paymentMethod?->name ?? 'Bank Transfer' }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Confirmed On</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <div class="amount">₦{{ number_format($transaction->amount, 2) }}</div>
                    <p style="color: #6b7280; margin: 0; font-size: 13px;">Confirmed Amount</p>
                </div>
            </div>

            @if($recipient instanceof \App\Models\Customer)
                <div class="note">
                    <strong>What's Next?</strong><br>
                    Your order is now being processed. You'll receive another email once your order has been dispatched.
                </div>

                <div style="text-align: center;">
                    <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug, 'orderNumber' => $order->order_number]) }}" class="button">Track Your Order</a>
                </div>
            @else
                <div style="text-align: center;">
                    <a href="{{ route('management.transactions.show', $transaction) }}" class="button">View Transaction</a>
                </div>
            @endif

            @if($recipient instanceof \App\Models\Customer)
                <p style="margin-top: 30px;">If you have any questions, please don't hesitate to contact <strong>{{ $store->name }}</strong>.</p>
                <p>Thank you for shopping with us!</p>
            @endif
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $store->name ?? config('app.name') }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this message.</p>
        </div>
    </div>
</body>
</html>
