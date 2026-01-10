<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .order-details { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .item:last-child { border-bottom: none; }
        .total { font-size: 18px; font-weight: bold; margin-top: 15px; padding-top: 15px; border-top: 2px solid #4CAF50; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Order Confirmed!</h1>
            <p>Thank you for your order</p>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <p>Your order has been received and is being processed. We'll send you another email when your order has been dispatched.</p>
            
            <div class="order-details">
                <h2>Order #{{ $order->order_number }}</h2>
                <p><strong>Order Date:</strong> {{ $order->created_at->format('F d, Y') }}</p>
                
                <h3>Delivery Information</h3>
                <p>
                    <strong>Delivery Address:</strong><br>
                    {{ $customer->full_address }}
                </p>
                @if($order->delivery_state && $order->delivery_area)
                <p><strong>Delivery Location:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                @endif
                @if($order->deliveryRoute)
                <p><strong>Estimated Delivery:</strong> {{ $order->deliveryRoute->delivery_days }} days</p>
                @endif
                
                <h3>Order Items</h3>
                @foreach($items as $item)
                <div class="item">
                    <strong>{{ $item->product_name }}</strong> ({{ $item->product_code }})<br>
                    Quantity: {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }} = ₦{{ number_format($item->subtotal, 2) }}
                </div>
                @endforeach
                
                <div class="total">
                    <p>Subtotal: ₦{{ number_format($subtotal ?? $order->subtotal, 2) }}</p>
                    <p>Shipping: ₦{{ number_format($order->shipping_fee, 2) }}</p>
                    <p>Tax: ₦{{ number_format($order->tax, 2) }}</p>
                    <p style="font-size: 20px; color: #4CAF50;">Total: ₦{{ number_format(($subtotal ?? $order->subtotal) + $order->shipping_fee + $order->tax, 2) }}</p>
                </div>
                
                <p><strong>Payment Status:</strong> {{ $order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status) }}</p>
            </div>
            
            @if($order->notes)
            <p><strong>Order Notes:</strong><br>{{ $order->notes }}</p>
            @endif
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}" class="button">Visit Our Store</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>If you have any questions, please contact our support team.</p>
        </div>
    </div>
</body>
</html>
