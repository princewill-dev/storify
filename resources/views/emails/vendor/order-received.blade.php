<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Order Received</title>
    <style>
        body { font-family: 'Inter', Arial, sans-serif; background: #f5f5f7; margin: 0; }
        .container { max-width: 640px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 18px; box-shadow: 0 20px 40px rgba(15,23,42,.08); }
        .header { text-align: center; padding-bottom: 16px; }
        .header img { max-height: 60px; margin-bottom: 12px; }
        .header h1 { font-size: 24px; margin: 0 0 4px; }
        .header p { margin: 0; color: #617b92; font-size: 14px; }
        .section { margin-top: 24px; }
        .section h2 { margin-bottom: 8px; font-size: 18px; }
        .order-details, .customer-details { border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; background: #f8fafc; }
        .order-details p, .customer-details p { margin: 4px 0; font-size: 14px; color: #1f2933; }
        .items { margin-top: 12px; }
        .item { padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
        .item:last-child { border-bottom: none; }
        .item strong { display: block; font-size: 16px; }
        .footer { margin-top: 32px; text-align: center; font-size: 12px; color: #9ca3af; }
        .btn { display: inline-flex; align-items: center; justify-content: center; background: #111827; color: white; padding: 12px 22px; border-radius: 10px; text-decoration: none; font-weight: 600; margin-top: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        @if(!empty($company->logo))
            <img src="{{ $company->logo }}" alt="{{ $company->name }}" />
        @endif
        <h1>Order #{{ $order->order_number }} received</h1>
        <p>{{ $store->name }} now has a new paid order.</p>
    </div>

    <div class="section order-details">
        <h2>Order summary</h2>
        <p><strong>Order total:</strong> ₦{{ number_format($order->total, 2) }}</p>
        <p><strong>Payment status:</strong> {{ $order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status) }}</p>
        <p><strong>Delivery:</strong> {{ $order->delivery_area }} · {{ $order->delivery_days ?? '—' }} days</p>
        <p><strong>Shipping fee:</strong> ₦{{ number_format($order->shipping_fee, 2) }}</p>
        <div class="items">
            @foreach($items as $item)
                <div class="item">
                    <strong>{{ $item->product_name }}</strong>
                    <span>Qty {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }}</span>
                    <span>Subtotal: ₦{{ number_format($item->subtotal, 2) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="section customer-details">
        <h2>Customer</h2>
        <p><strong>Name:</strong> {{ $customer->full_name }}</p>
        <p><strong>Email:</strong> {{ $customer->email }}</p>
        <p><strong>Phone:</strong> {{ $customer->recipient_phone ?? $customer->phone ?? '—' }}</p>
        <p><strong>Address:</strong> {{ $order->delivery_area }}, {{ $order->delivery_state }}</p>
        @if($order->notes)
            <p><strong>Notes:</strong> {{ $order->notes }}</p>
        @endif
    </div>

    <div class="section" style="text-align:center;">
        <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $order]) }}" class="btn">View order in dashboard</a>
    </div>

    <div class="footer">
        <p>{{ $company->name }} · {{ $company->address }}</p>
        <p>Need help? {{ $company->email }} · {{ $company->phone }}</p>
    </div>
</div>
</body>
</html>
