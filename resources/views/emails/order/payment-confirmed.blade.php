<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 30px 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { background: #f9f9f9; padding: 30px 20px; }
        .order-details { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #4CAF50; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: bold; color: #666; }
        .detail-value { color: #333; }
        .amount { font-size: 24px; font-weight: bold; color: #4CAF50; margin: 15px 0; }
        .success-icon { font-size: 48px; color: #4CAF50; margin-bottom: 15px; }
        .button { display: inline-block; padding: 12px 30px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .note { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 15px; margin: 20px 0; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="success-icon">✓</div>
            <h1>Payment Confirmed!</h1>
            <p>Your payment has been verified and approved</p>
        </div>
        
        <div class="content">
            <p>Hello <strong>{{ $customer->first_name }}</strong>,</p>
            
            <p>Great news! <strong>{{ $store->name }}</strong> has confirmed your payment for order <strong>{{ $order->order_number }}</strong>.</p>
            
            <div class="order-details">
                <h3 style="margin-top: 0; color: #4CAF50;">Payment Confirmed</h3>
                
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
                    <span class="detail-label">Confirmed On:</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <div class="amount">₦{{ number_format($transaction->amount, 2) }}</div>
                    <p style="color: #666; margin: 0;">Payment Amount</p>
                </div>
            </div>
            
            <div class="note">
                <strong>What's Next?</strong><br>
                Your order is now being processed. You'll receive another email once your order has been dispatched for delivery.
            </div>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}" class="button">Track Order Status</a>
            </div>
            
            <p style="margin-top: 30px;">If you have any questions about your order, please don't hesitate to contact us.</p>
            
            <p>Thank you for shopping with {{ $store->name }}!</p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $store->name }}. All rights reserved.</p>
            <p>This is an automated email. Please do not reply to this message.</p>
        </div>
    </div>
</body>
</html>
