<!DOCTYPE html>
<html>
<head>
    <title>Family Pack Subscription Activated</title>
</head>
<body>
    <h2>Hello {{ $familyPackOrder->customer->first_name }},</h2>
    
    <p>Great news! Your Family Pack subscription (<strong>{{ $familyPackOrder->pack_code }}</strong>) has been activated.</p>
    
    <p>Your delivery schedule has been generated. You can view your upcoming deliveries and subscription details in your dashboard.</p>
    
    <h3>Subscription Details:</h3>
    <ul>
        <li><strong>Store:</strong> {{ $familyPackOrder->store->name }}</li>
        <li><strong>Payment Interval:</strong> {{ $familyPackOrder->payment_interval }}</li>
        <li><strong>Delivery Interval:</strong> {{ $familyPackOrder->deliveryInterval->name }}</li>
    </ul>
    
    <p>
        <a href="{{ route('family-pack.review', ['store_slug' => $familyPackOrder->store->slug, 'packCode' => $familyPackOrder->pack_code]) }}" style="background-color: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">View Subscription</a>
    </p>
    
    <p>Thank you for subscribing!</p>
</body>
</html>
