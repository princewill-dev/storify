<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status Update</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2196F3; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .status-box { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; text-align: center; }
        .status-change { font-size: 24px; margin: 20px 0; }
        .old-status { color: #999; text-decoration: line-through; }
        .new-status { color: #2196F3; font-weight: bold; }
        .arrow { color: #2196F3; font-size: 30px; margin: 0 10px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Order Status Updated</h1>
            <p>Order #{{ $order->order_number }}</p>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <p>The status of your order has been updated.</p>
            
            <div class="status-box">
                <h2>Status Change</h2>
                <div class="status-change">
                    <span class="old-status">{{ ucfirst($oldStatus) }}</span>
                    <span class="arrow">→</span>
                    <span class="new-status">{{ ucfirst($newStatus) }}</span>
                </div>
                
                @if($newStatus === 'accepted')
                <p>Great news! Your order has been accepted and will be processed shortly.</p>
                @elseif($newStatus === 'processing')
                <p>Your order is currently being prepared.</p>
                @elseif($newStatus === 'dispatched')
                <p>Your order has been dispatched and is on its way to you!</p>
                @elseif($newStatus === 'delivered')
                <p>Your order has been delivered. We hope you enjoy your purchase!</p>
                @elseif($newStatus === 'completed')
                <p>Your order is complete. Thank you for shopping with us!</p>
                @elseif($newStatus === 'cancelled')
                <p>Your order has been cancelled. If you have any questions, please contact our support team.</p>
                @endif
            </div>
            
            <div style="background: white; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3>Order Summary</h3>
                <p><strong>Order Number:</strong> {{ $order->order_number }}</p>
                <p><strong>Order Date:</strong> {{ $order->created_at->format('F d, Y') }}</p>
                <p><strong>Total Amount:</strong> ₦{{ number_format($order->total, 2) }}</p>
                @if($order->delivery_state && $order->delivery_area)
                <p><strong>Delivery Location:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                @endif
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}" class="button">Visit Our Store</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>If you have any questions about your order, please contact our support team.</p>
        </div>
    </div>
</body>
</html>
