<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Processed</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2196F3; color: white; padding: 30px 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { background: #f9f9f9; padding: 30px 20px; }
        .order-details { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #2196F3; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: bold; color: #666; }
        .detail-value { color: #333; }
        .amount { font-size: 24px; font-weight: bold; color: #2196F3; margin: 15px 0; }
        .info-icon { font-size: 48px; color: #2196F3; margin-bottom: 15px; }
        .button { display: inline-block; padding: 12px 30px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; font-weight: bold; }
        .button-secondary { background: #757575; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .alert { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 3px; }
        .reason-box { background: #fff3e0; padding: 15px; margin: 20px 0; border-radius: 5px; border: 1px solid #ffb74d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="info-icon">↩</div>
            <h1>Refund Processed</h1>
            <p>Your payment refund has been initiated</p>
        </div>
        
        <div class="content">
            <p>Hello <strong>{{ $customer->first_name }}</strong>,</p>
            
            <p>We're writing to confirm that <strong>{{ $store->name }}</strong> has processed a refund for your order <strong>{{ $order->order_number }}</strong>.</p>
            
            <div class="order-details">
                <h3 style="margin-top: 0; color: #2196F3;">Refund Details</h3>
                
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
                    <span class="detail-label">Refund Initiated On:</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <div class="amount">₦{{ number_format($transaction->amount, 2) }}</div>
                    <p style="color: #666; margin: 0;">Refund Amount</p>
                </div>
            </div>
            
            <div class="reason-box">
                <strong style="color: #f57c00;">Refund Reason:</strong>
                <p style="margin: 10px 0 0; color: #555;">{{ $reason }}</p>
            </div>
            
            <div class="alert">
                <strong>What Happens Next?</strong><br>
                <ul style="margin: 10px 0 0; padding-left: 20px;">
                    <li>Your refund will be processed within 5-10 business days</li>
                    <li>The amount will be credited back to your original payment method</li>
                    <li>You'll receive the funds in the same account/card used for payment</li>
                    <li>If you have any questions, please contact our support team</li>
                </ul>
            </div>
            
            <div style="text-align: center;">
                <a href="{{ config('app.url') }}" class="button">View Order</a>
                @if($store->support_email)
                <a href="mailto:{{ $store->support_email }}" class="button button-secondary">Contact Support</a>
                @endif
            </div>
            
            <p style="margin-top: 30px;">We apologize for any inconvenience. If you have any questions about this refund, please don't hesitate to reach out.</p>
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
