<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Delivered</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .delivery-box { background: white; padding: 25px; margin: 20px 0; border-radius: 5px; text-align: center; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .icon { font-size: 64px; margin-bottom: 15px; }
        .feedback-box { background: #e8f5e9; padding: 20px; margin: 20px 0; border-radius: 5px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Order Delivered Successfully!</h1>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <div class="delivery-box">
                <div class="icon">🎉</div>
                <h2>Your Order Has Been Delivered!</h2>
                <p style="font-size: 18px;">Order #{{ $order->order_number }}</p>
            </div>
            
            <p>We're happy to confirm that your order has been successfully delivered to your address.</p>
            
            <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3>Order Details</h3>
                <p><strong>Order Number:</strong> {{ $order->order_number }}</p>
                <p><strong>Order Date:</strong> {{ $order->created_at->format('F d, Y') }}</p>
                <p><strong>Delivery Date:</strong> {{ now()->format('F d, Y') }}</p>
                <p><strong>Total Amount:</strong> ₦{{ number_format($order->total, 2) }}</p>
            </div>
            
            <div class="feedback-box">
                <h3>How Was Your Experience?</h3>
                <p>We'd love to hear your feedback about your purchase and delivery experience.</p>
                <p style="margin-top: 15px;">
                    <a href="{{ $appUrl }}" class="button">Share Your Feedback</a>
                </p>
            </div>
            
            <p><strong>Need Help?</strong></p>
            <p>If you have any issues with your order or need assistance, please don't hesitate to contact our support team.</p>
            
            <p style="text-align: center; margin-top: 30px;">
                <a href="{{ $appUrl }}" class="button">Continue Shopping</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>Thank you for shopping with us!</p>
        </div>
    </div>
</body>
</html>
