<!DOCTYPE html>
<html>
<head>
    <title>New Family Pack Request</title>
</head>
<body>
    <h2>New Request Received</h2>
    
    <p>A new Family Pack request has been submitted.</p>
    
    <h3>Details:</h3>
    <ul>
        <li><strong>Pack Code:</strong> {{ $familyPackOrder->pack_code }}</li>
        <li><strong>Customer:</strong> {{ $familyPackOrder->customer->first_name }} {{ $familyPackOrder->customer->last_name }}</li>
        <li><strong>Store:</strong> {{ $familyPackOrder->store->name }}</li>
        <li><strong>Type:</strong> {{ ucfirst($familyPackOrder->pack_type) }}</li>
    </ul>
    
    <p>
        <a href="{{ route('admin.family-packs.show-by-code', $familyPackOrder->pack_code) }}">Review Request</a>
    </p>
</body>
</html>
