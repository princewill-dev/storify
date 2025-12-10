<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Customer Response Received</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #2c3e50;">Customer Response Received</h2>
    
    <p>Hello Admin,</p>
    
    <p>Customer <strong>{{ $bulkOrder->customer->full_name }}</strong> has submitted a response for Bulk Order <strong>#{{ $bulkOrder->bulk_code }}</strong>.</p>
    
    <div style="background-color: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;">
        <strong>Customer Notes:</strong><br>
        {{ $bulkOrder->notes ?: 'No notes provided.' }}
    </div>

    <div style="background-color: #e9ecef; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <strong>New Total Estimate:</strong> ₦{{ number_format($bulkOrder->estimated_total, 2) }}
    </div>
    
    <p>Please review the customer's changes and respond.</p>
    
    <p style="margin: 30px 0;">
        <a href="{{ route('admin.bulk-orders.show', $bulkOrder) }}" 
           style="background-color: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            View Bulk Order
        </a>
    </p>
    
    <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 30px 0;">
    
    <p style="color: #6c757d; font-size: 12px;">
        {{ config('app.name') }} System Notification
    </p>
</body>
</html>
