<!DOCTYPE html>
<html>
<head>
    <title>New Order Notification</title>
</head>
<body>
    <h2>New Order Received</h2>
    
    <p>Hello,</p>
    
    <p>You have received a new order (<strong>#{{ $order->order_number }}</strong>) from your store <strong>{{ $order->store->name }}</strong>.</p>
    
    <h3>Order Details:</h3>
    <ul>
        <li><strong>Customer:</strong> {{ $order->customer->first_name }} {{ $order->customer->last_name }}</li>
        <li><strong>Total Amount:</strong> ₦{{ number_format($order->total, 2) }}</li>
        <li><strong>Date:</strong> {{ $order->created_at->format('M d, Y H:i') }}</li>
    </ul>
    
    <h3>Items:</h3>
    <ul>
        @foreach($order->items as $item)
            <li>{{ $item->quantity }}x {{ $item->product_name }} - ₦{{ number_format($item->subtotal, 2) }}</li>
        @endforeach
    </ul>
    
    <p>Please login to your vendor dashboard to process this order.</p>
    
    <p>Thank you!</p>
</body>
</html>
