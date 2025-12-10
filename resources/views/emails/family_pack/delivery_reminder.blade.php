<!DOCTYPE html>
<html>
<head>
    <title>Delivery Reminder</title>
</head>
<body>
    <h2>Hello {{ $delivery->familyPackOrder->customer->first_name }},</h2>
    
    @if($type === 'pre_delivery')
        <p>This is a reminder that you have a scheduled delivery coming up tomorrow ({{ $delivery->scheduled_date->format('M d, Y') }}).</p>
        <p>Please ensure your payment is ready/processed to avoid any delays.</p>
    @elseif($type === 'delivery_day')
        <p><strong>Your delivery is scheduled for today!</strong></p>
        <p>We are preparing your pack for dispatch.</p>
    @elseif($type === 'overdue')
        <p style="color: red;"><strong>Action Required: Payment Overdue</strong></p>
        <p>We were unable to process payment for your delivery scheduled for {{ $delivery->scheduled_date->format('M d, Y') }}.</p>
        <p>Please update your payment method or make a payment immediately. If payment is not received by the end of today, this delivery will be cancelled.</p>
    @endif
    
    <h3>Delivery Details:</h3>
    <ul>
        <li><strong>Cycle:</strong> {{ $delivery->cycle_number }}</li>
        <li><strong>Date:</strong> {{ $delivery->scheduled_date->format('M d, Y') }}</li>
        <li><strong>Status:</strong> {{ ucfirst($delivery->status) }}</li>
    </ul>
    
    <p>
        <a href="{{ route('family-pack.review', $delivery->familyPackOrder->pack_code) }}">View Details</a>
    </p>
    
    <p>Thank you!</p>
</body>
</html>
