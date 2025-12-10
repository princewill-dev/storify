<!DOCTYPE html>
<html>
<head>
    <title>Family Pack Request Updated</title>
</head>
<body>
    <h2>Hello {{ $familyPackOrder->customer->first_name }},</h2>
    
    <p>There has been an update to your Family Pack request (<strong>{{ $familyPackOrder->pack_code }}</strong>).</p>
    
    <p>The admin has reviewed your request and updated the pricing/details. Please review the changes and accept to proceed.</p>
    
    @if($familyPackOrder->review_notes)
        <div style="background-color: #f8f9fa; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0;">
            <strong>Admin Note:</strong><br>
            {{ $familyPackOrder->review_notes }}
        </div>
    @endif
    
    <p>
        <a href="{{ route('family-pack.review', ['store_slug' => $familyPackOrder->store->slug, 'packCode' => $familyPackOrder->pack_code]) }}" style="background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Review & Accept</a>
    </p>
    
    <p>Thank you!</p>
</body>
</html>
