<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bulk Order Update</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #2c3e50;">Update on your Bulk Order #{{ $bulkOrder->bulk_code }}</h2>
    
    <p>Hello {{ $bulkOrder->customer->first_name }},</p>
    
    <p>We have reviewed your bulk order request and made some updates.</p>
    
    @if($bulkOrder->review_notes)
    <div style="background-color: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;">
        <strong>Admin Notes:</strong><br>
        {{ $bulkOrder->review_notes }}
    </div>
    @endif
    
    <div style="background-color: #e9ecef; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <strong>Current Total:</strong> ₦{{ number_format($bulkOrder->estimated_total, 2) }}
    </div>
    
    <p>Please review the changes by clicking the link below:</p>
    
    <p style="margin: 30px 0;">
        <a href="{{ route('bulk.order.review', ['store_slug' => $bulkOrder->store->slug, 'bulkCode' => $bulkOrder->bulk_code]) }}" 
           style="background-color: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            View Bulk Order
        </a>
    </p>
    
    <p>If you have any questions, please contact us.</p>
    
    <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 30px 0;">
    
    <p style="color: #6c757d; font-size: 12px;">
        Thanks,<br>
        {{ config('app.name') }}
    </p>
</body>
</html>
