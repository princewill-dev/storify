<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Accepted Bulk Order</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #28a745; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;">
        <h1 style="margin: 0; font-size: 24px;">✓ Customer Accepted Order</h1>
    </div>
    
    <div style="background-color: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-top: none; border-radius: 0 0 5px 5px;">
        <div style="background-color: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin-bottom: 20px; border-radius: 3px;">
            <p style="margin: 0; color: #0c5460;">
                <strong>{{ $customerName }}</strong> has accepted bulk order <strong>{{ $bulkOrder->bulk_code }}</strong>
            </p>
        </div>

        <h2 style="color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; margin-top: 0;">Order Details</h2>
        
        <table style="width: 100%; margin-bottom: 20px;">
            <tr>
                <td style="padding: 8px 0;"><strong>Order Code:</strong></td>
                <td style="padding: 8px 0;">{{ $bulkOrder->bulk_code }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Customer:</strong></td>
                <td style="padding: 8px 0;">{{ $customerName }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Email:</strong></td>
                <td style="padding: 8px 0;">{{ $customer->email }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Phone:</strong></td>
                <td style="padding: 8px 0;">{{ $customer->phone }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Store:</strong></td>
                <td style="padding: 8px 0;">{{ $store->name }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Status:</strong></td>
                <td style="padding: 8px 0;"><span style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">APPROVED</span></td>
            </tr>
            <tr>
                <td style="padding: 8px 0;"><strong>Accepted At:</strong></td>
                <td style="padding: 8px 0;">{{ $bulkOrder->customer_accepted_at->format('M d, Y h:i A') }}</td>
            </tr>
        </table>

        <h3 style="color: #333; margin-top: 30px; margin-bottom: 15px;">Order Items</h3>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr style="background-color: #e9ecef;">
                    <th style="padding: 10px; text-align: left; border: 1px solid #dee2e6;">Product</th>
                    <th style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">Qty</th>
                    <th style="padding: 10px; text-align: right; border: 1px solid #dee2e6;">Price</th>
                    <th style="padding: 10px; text-align: right; border: 1px solid #dee2e6;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td style="padding: 10px; border: 1px solid #dee2e6;">
                        {{ $item->product_name }}
                        @if($item->is_custom)
                            <span style="background-color: #17a2b8; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">CUSTOM</span>
                        @endif
                    </td>
                    <td style="padding: 10px; text-align: center; border: 1px solid #dee2e6;">{{ number_format($item->quantity) }}</td>
                    <td style="padding: 10px; text-align: right; border: 1px solid #dee2e6;">
                        @if($item->is_custom)
                            ₦{{ number_format($item->budgeted_amount, 2) }}
                        @else
                            ₦{{ number_format($item->unit_price, 2) }}
                        @endif
                    </td>
                    <td style="padding: 10px; text-align: right; border: 1px solid #dee2e6;">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background-color: #f8f9fa; font-weight: bold;">
                    <td colspan="3" style="padding: 12px; text-align: right; border: 1px solid #dee2e6;">Total:</td>
                    <td style="padding: 12px; text-align: right; border: 1px solid #dee2e6; color: #28a745; font-size: 18px;">
                        ₦{{ number_format($bulkOrder->estimated_total, 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>

        <div style="background-color: #fff3cd; border-left: 4px solid #856404; padding: 15px; margin: 20px 0; border-radius: 3px;">
            <p style="margin: 0; color: #856404;">
                <strong>⚡ Action Required:</strong> Please finalize this order and send the customer a payment link to complete the purchase.
            </p>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $adminUrl }}" style="display: inline-block; background-color: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                View Order in Admin Panel
            </a>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; text-align: center;">
            <p style="margin: 5px 0;">{{ $company->name }}</p>
            @if($company->email)
                <p style="margin: 5px 0;">Email: {{ $company->email }}</p>
            @endif
            @if($company->phone)
                <p style="margin: 5px 0;">Phone: {{ $company->phone }}</p>
            @endif
        </div>
    </div>
</body>
</html>
