<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Order Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FF9800; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .order-details { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .item:last-child { border-bottom: none; }
        .total { font-size: 18px; font-weight: bold; margin-top: 15px; padding-top: 15px; border-top: 2px solid #FF9800; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #FF9800; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .alert { background: #fff3cd; border-left: 4px solid #FF9800; padding: 15px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛍️ New Order Received!</h1>
            <p>Order #{{ $order->order_number }}</p>
        </div>
        
        <div class="content">
            <div class="alert">
                <strong>Action Required:</strong> A new order has been placed and requires your attention.
            </div>
            
            <div class="order-details">
                <h2>Order Details</h2>
                <p><strong>Order Number:</strong> {{ $order->order_number }}</p>
                <p><strong>Order Date:</strong> {{ $order->created_at->format('F d, Y H:i:s') }}</p>
                <p><strong>Order Total:</strong> ₦{{ number_format($order->total, 2) }}</p>
                <p><strong>Payment Status:</strong> <span style="color: {{ ($order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : $order->payment_status) === 'paid' ? '#4CAF50' : '#FF9800' }}">{{ $order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status) }}</span></p>
                <p><strong>Order Status:</strong> {{ ucfirst($order->status->value ?? $order->status) }}</p>
                
                <h3>Store Information</h3>
                <p><strong>Store:</strong> {{ $store->name }}</p>
                <p><strong>Store Slug:</strong> {{ $store->slug }}</p>
                
                <h3>Customer Information</h3>
                <p>
                    <strong>Name:</strong> {{ $customer->full_name }}<br>
                    <strong>Email:</strong> {{ $customer->email }}<br>
                    <strong>Phone:</strong> {{ $customer->phone }}<br>
                    <strong>Address:</strong> {{ $customer->full_address }}
                </p>
                
                @if($order->delivery_state && $order->delivery_area)
                <p><strong>Delivery Location:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
                @endif
                
                <h3>Order Items ({{ $items->count() }} items)</h3>
                @foreach($items as $item)
                <div class="item">
                    <strong>{{ $item->product_name }}</strong> ({{ $item->product_code }})<br>
                    Quantity: {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }} = ₦{{ number_format($item->subtotal, 2) }}
                </div>
                @endforeach
                
                <div class="total">
                    <p>Subtotal: ₦{{ number_format($order->subtotal, 2) }}</p>
                    <p>Shipping: ₦{{ number_format($order->shipping_fee, 2) }}</p>
                    <p>Tax: ₦{{ number_format($order->tax, 2) }}</p>
                    <p style="font-size: 20px; color: #FF9800;">Total: ₦{{ number_format($order->total, 2) }}</p>
                </div>
                
                @if($order->notes)
                <p><strong>Customer Notes:</strong><br>{{ $order->notes }}</p>
                @endif
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $adminUrl }}" class="button">View Order in Admin Panel</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>This is an automated notification from your e-commerce system.</p>
        </div>
    </div>
</body>
</html>
