<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Issue</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc2626; color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 8px 0 0; opacity: 0.9; font-size: 14px; }
        .content { background: #ffffff; padding: 30px 24px; }
        .order-details { background: #f9fafb; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #e5e7eb; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #6b7280; font-size: 13px; }
        .detail-value { color: #111827; font-size: 14px; font-weight: 500; }
        .warning-icon { font-size: 48px; color: #fca5a5; margin-bottom: 12px; }
        .button { display: inline-block; padding: 12px 28px; background: #dc2626; color: white; text-decoration: none; border-radius: 6px; margin: 8px 4px; font-weight: 600; font-size: 14px; }
        .button-secondary { background: #4b5563; }
        .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e7eb; }
        .alert { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; }
        .reason-box { background: #fef2f2; border: 1px solid #fecaca; padding: 15px; margin: 20px 0; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="warning-icon">&#9888;</div>
            <h1>Payment Issue</h1>
            <p>There was an issue with a payment</p>
        </div>

        <div class="content">
            <p>Hello <strong>{{ $recipient?->name ?? $recipient->first_name ?? 'Admin' }}</strong>,</p>

            @if($recipient instanceof \App\Models\Customer)
                <p>We regret to inform you that <strong>{{ $store->name }}</strong> could not confirm your payment for order <strong>{{ $order->order_number }}</strong>.</p>
            @else
                <p>A payment for <strong>{{ $store->name }}</strong> has been rejected. Here are the details:</p>
            @endif

            <div class="order-details">
                <h3 style="margin-top: 0; color: #dc2626; font-size: 16px;">Payment Details</h3>

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
                    <span class="detail-label">Amount</span>
                    <span class="detail-value" style="color: #dc2626; font-weight: 600;">₦{{ number_format($transaction->amount, 2) }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Rejected On</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>
            </div>

            @if(!empty($reason))
            <div class="reason-box">
                <strong style="color: #991b1b;">Reason:</strong>
                <p style="margin: 8px 0 0; color: #7f1d1d;">{{ $reason }}</p>
            </div>
            @endif

            @if($recipient instanceof \App\Models\Customer)
                <div class="alert">
                    <strong>What Should You Do?</strong><br>
                    Please contact the store's support team or attempt to resubmit your payment with the correct amount and payment proof. We're here to help resolve this quickly.
                </div>

                <div style="text-align: center;">
                    <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug, 'orderNumber' => $order->order_number]) }}" class="button">View Order</a>
                    @if($store->support_email)
                    <a href="mailto:{{ $store->support_email }}" class="button button-secondary">Contact Support</a>
                    @endif
                </div>

                <p style="margin-top: 30px;">We apologize for any inconvenience this may have caused.</p>
            @else
                <div style="text-align: center;">
                    <a href="{{ route('management.transactions.show', $transaction) }}" class="button">View Transaction</a>
                </div>
            @endif
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $store->name ?? config('app.name') }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this message.</p>
        </div>
    </div>
</body>
</html>
