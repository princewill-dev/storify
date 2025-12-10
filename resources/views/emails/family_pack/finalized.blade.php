<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Family Pack First Delivery - Payment Pending</title>
</head>
<body>
    <h2>Hello {{ $familyPackOrder->customer->first_name }},</h2>

    <p>Your Family Pack request <strong>{{ $familyPackOrder->pack_code }}</strong> has been finalized and the first delivery has been created.</p>

    <p>Please complete your payment to proceed with processing and delivery.</p>

    <h3>Order Summary</h3>
    <ul>
        <li><strong>Order #:</strong> {{ $order->order_number }}</li>
        <li><strong>Store:</strong> {{ $familyPackOrder->store->name }}</li>
        <li><strong>Subtotal:</strong> ₦{{ number_format($order->subtotal, 2) }}</li>
        <li><strong>Shipping:</strong> ₦{{ number_format($order->shipping_fee, 2) }}</li>
        <li><strong>Tax:</strong> ₦{{ number_format($order->tax, 2) }}</li>
        <li><strong>Total:</strong> ₦{{ number_format($order->total, 2) }}</li>
    </ul>

    <p>
        <a href="{{ route('checkout.payment-methods', ['store_slug' => $familyPackOrder->store->slug, 'order' => $order->order_number]) }}"
           style="background-color:#4f46e5;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;">
           Choose Payment Method
        </a>
    </p>

    <p>If you have any questions, reply to this email and we’ll be happy to help.</p>

    <p>Thank you!</p>
</body>
</html>
