<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bulk Order Ready for Payment</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #28a745;">Your Bulk Order is Ready!</h2>
    
    <p>Hello {{ $bulkOrder->customer->first_name }},</p>
    
    <p>Great news! Your bulk order <strong>#{{ $bulkOrder->bulk_code }}</strong> has been finalized and is ready for payment.</p>
    
    <p>A new order <strong>#{{ $order->order_number }}</strong> has been created for you.</p>
    
    <div style="background-color: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
        <strong>Total Amount:</strong> ₦{{ number_format($order->total, 2) }}
    </div>
    
    <p>Please click the button below to select your payment method and complete the purchase.</p>
    
    <p style="margin: 30px 0;">
        <a href="{{ route('checkout.payment-methods', ['store_subdomain' => $bulkOrder->store->slug, 'order' => $order->order_number]) }}" 
           style="background-color: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            Proceed to Payment
        </a>
    </p>
    
    <hr style="border: none; border-top: 1px solid #e1e4e8; margin: 30px 0;">
    
    <p style="color: #6c757d; font-size: 12px;">
        Thanks,<br>
        {{ config('app.name') }}
    </p>
</body>
</html>
