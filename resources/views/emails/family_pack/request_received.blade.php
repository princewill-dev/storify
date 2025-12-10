<!DOCTYPE html>
<html>
<head>
    <title>Family Pack Request Received</title>
</head>
<body>
    <h2>Hello {{ $familyPackOrder->customer->first_name }},</h2>
    
    <p>We have received your Family Pack request (<strong>{{ $familyPackOrder->pack_code }}</strong>).</p>
    
    <p>Our team will review your request, including any custom items, and get back to you shortly with the final pricing.</p>
    
    <h3>Request Details:</h3>
    <ul>
        <li><strong>Store:</strong> {{ $familyPackOrder->store->name }}</li>
        <li><strong>Type:</strong> {{ ucfirst($familyPackOrder->pack_type) }}</li>
        <li><strong>Date:</strong> {{ $familyPackOrder->created_at->format('M d, Y') }}</li>
    </ul>
    
    <p>You can track the status of your request in your dashboard.</p>
    
    <p>Thank you for shopping with us!</p>
</body>
</html>
