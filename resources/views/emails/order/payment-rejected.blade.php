<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Issue</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FF5722; color: white; padding: 30px 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { background: #f9f9f9; padding: 30px 20px; }
        .order-details { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #FF5722; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: bold; color: #666; }
        .detail-value { color: #333; }
        .warning-icon { font-size: 48px; color: #FF5722; margin-bottom: 15px; }
        .button { display: inline-block; padding: 12px 30px; background: #FF5722; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0 10px; font-weight: bold; }
        .button-secondary { background: #757575; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .alert { background: #fff3e0; border-left: 4px solid #FF9800; padding: 15px; margin: 20px 0; border-radius: 3px; }
        .reason-box { background: #ffebee; padding: 15px; margin: 20px 0; border-radius: 5px; border: 1px solid #ef9a9a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="warning-icon">⚠</div>
            <h1>Payment Issue</h1>
            <p>There was an issue with your payment</p>
        </div>
        
        <div class="content">
            <p>Hello <strong>{{ $customer->first_name }}</strong>,</p>
            
            <p>We regret to inform you that <strong>{{ $store->name }}</strong> could not confirm your payment for order <strong>{{ $order->order_number }}</strong>.</p>
            
            <div class="order-details">
                <h3 style="margin-top: 0; color: #FF5722;">Payment Not Confirmed</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Order Number:</span>
                    <span class="detail-value">{{ $order->order_number }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Transaction ID:</span>
                    <span class="detail-value">{{ $transaction->reference }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Payment Method:</span>
                    <span class="detail-value">{{ $transaction->paymentMethod?->name ?? 'N/A' }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value">₦{{ number_format($transaction->amount, 2) }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Rejected On:</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>
            </div>
            
            @if(!empty($reason))
            <div class="reason-box">
                <strong style="color: #c62828;">Reason for Rejection:</strong>
                <p style="margin: 10px 0 0; color: #555;">{{ $reason }}</p>
            </div>
            @endif
            
            <div class="alert">
                <strong>What Should You Do Next?</strong><br>
                Please contact our support team or attempt to resubmit your payment. We're here to help resolve this issue quickly.
            </div>
            
            <div style="text-align: center;">
                @if($store->support_email)
                <a href="mailto:{{ $store->support_email }}" class="button">Contact Support</a>
                @endif
                <a href="{{ config('app.url') }}" class="button button-secondary">View Order</a>
            </div>
            
            <p style="margin-top: 30px;">If you have already contacted support or believe this is an error, please reach out to us immediately.</p>
            
            <p>We apologize for any inconvenience this may have caused.</p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $store->name }}. All rights reserved.</p>
            <p>This is an automated email. Please do not reply to this message.</p>
            @if($store->support_email)
            <p>For support, email us at <a href="mailto:{{ $store->support_email }}">{{ $store->support_email }}</a></p>
            @endif
        </div>
    </div>
</body>
</html>
