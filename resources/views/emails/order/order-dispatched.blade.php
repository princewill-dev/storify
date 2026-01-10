<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Dispatched</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FF5722; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .dispatch-box { background: white; padding: 25px; margin: 20px 0; border-radius: 5px; text-align: center; }
        .tracking-info { background: #fff3e0; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #FF5722; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #FF5722; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .icon { font-size: 48px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Your Order is On Its Way!</h1>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <div class="dispatch-box">
                <div class="icon">🚚</div>
                <h2>Order Dispatched</h2>
                <p style="font-size: 18px;">Your order #{{ $order->order_number }} has been dispatched!</p>
            </div>
            
            <p>Great news! Your order has left our warehouse and is now on its way to you.</p>
            
            @if($deliveryRoute)
            <div class="tracking-info">
                <h3>Delivery Information</h3>
                <p><strong>Delivery Location:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                <p><strong>Estimated Delivery Time:</strong> {{ $deliveryRoute->delivery_days }} days from dispatch</p>
                <p><strong>Delivery Address:</strong><br>{{ $customer->full_address }}</p>
            </div>
            @endif
            
            <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3>Order Summary</h3>
                <p><strong>Order Number:</strong> {{ $order->order_number }}</p>
                <p><strong>Order Date:</strong> {{ $order->created_at->format('F d, Y') }}</p>
                <p><strong>Total Amount:</strong> ₦{{ number_format($order->total, 2) }}</p>
            </div>
            
            <p><strong>What's Next?</strong></p>
            <ul>
                <li>Your order is being delivered to your address</li>
                <li>You'll receive another email when it's delivered</li>
                <li>Make sure someone is available to receive the package</li>
            </ul>
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}" class="button">Visit Our Store</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>If you have any questions about your delivery, please contact our support team.</p>
        </div>
    </div>
</body>
</html>
