<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Bulk Order</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            padding: 30px 40px;
            background-color: #dc3545;
            color: white;
        }
        .header-title {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        .email-body {
            padding: 40px 40px 30px;
        }
        .alert-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .order-code {
            font-size: 28px;
            font-weight: 700;
            color: #dc3545;
            margin: 10px 0;
        }
        .info-text {
            color: #666666;
            font-size: 14px;
            line-height: 1.6;
            margin: 15px 0;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #000000;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e5e5;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        .detail-item {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
        }
        .detail-label {
            color: #999999;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .detail-value {
            color: #000000;
            font-weight: 600;
            font-size: 16px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .items-table th {
            background-color: #f5f5f5;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            color: #666666;
            border-bottom: 2px solid #e5e5e5;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            padding: 14px 28px;
            background-color: #dc3545;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
            margin: 20px 0;
        }
        .footer {
            padding: 30px 40px;
            background-color: #fafafa;
            border-top: 1px solid #e5e5e5;
        }
        .footer-text {
            color: #666666;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }
        .company-info {
            color: #999999;
            font-size: 12px;
            margin-top: 15px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1 class="header-title">🎉 New Bulk Order Received</h1>
        </div>
        
        <div class="email-body">
            <div class="alert-box">
                <p style="margin: 0; font-weight: 600; color: #000;">Action Required: Review Bulk Order</p>
                <p class="order-code">{{ $bulkOrder->bulk_code }}</p>
            </div>
            
            <p class="info-text">
                A new bulk order has been submitted and requires your review. Please verify product availability and confirm pricing.
            </p>
            
            <h2 class="section-title">Customer Information</h2>
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Customer Name</div>
                    <div class="detail-value">{{ $customer->first_name }} {{ $customer->last_name }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">{{ $customer->email }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value">{{ $customer->phone ?? 'N/A' }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Order Date</div>
                    <div class="detail-value">{{ $bulkOrder->created_at->format('M d, Y h:i A') }}</div>
                </div>
            </div>

            <h2 class="section-title">Order Details</h2>
            @if($items->count() > 0)
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: center;">Quantity</th>
                            <th style="text-align: right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->product_name }}</strong><br>
                                <small style="color: #999;">{{ $item->product_code }}</small>
                            </td>
                            <td style="text-align: center;">{{ number_format($item->quantity) }}</td>
                            <td style="text-align: right;">₦{{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($bulkOrder->custom_items)
                <h3 style="font-size: 15px; margin-top: 25px; color: #666;">Custom Product Requests</h3>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align: center;">Quantity</th>
                            <th style="text-align: right;">Budget</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bulkOrder->custom_items as $item)
                        <tr>
                            <td>{{ $item['name'] }}</td>
                            <td style="text-align: center;">{{ number_format($item['quantity']) }}</td>
                            <td style="text-align: right;">₦{{ number_format($item['budgeted_amount'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="details-grid" style="margin-top: 30px;">
                <div class="detail-item" style="background-color: #e6f9f3;">
                    <div class="detail-label">Estimated Total</div>
                    <div class="detail-value" style="color: #00D084; font-size: 24px;">₦{{ number_format($bulkOrder->estimated_total, 2) }}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" style="color: #ffa500;">Pending Review</div>
                </div>
            </div>

            <h2 class="section-title">Delivery Information</h2>
            <div style="background-color: #f9f9f9; padding: 20px; border-radius: 6px;">
                <p style="margin: 0; font-weight: 600; color: #000;">{{ $deliveryAddress->recipient_name }}</p>
                <p style="margin: 5px 0 0 0; color: #666; font-size: 14px; line-height: 1.6;">
                    {{ $deliveryAddress->street_address }}<br>
                    @if($deliveryAddress->apartment)
                        {{ $deliveryAddress->apartment }}<br>
                    @endif
                    {{ $deliveryAddress->deliveryRoute->area ?? 'N/A' }}, {{ $deliveryAddress->deliveryRoute->state ?? 'N/A' }}<br>
                    Phone: {{ $deliveryAddress->recipient_phone }}
                </p>
            </div>

            @if($bulkOrder->notes)
                <h2 class="section-title">Customer Notes</h2>
                <div style="background-color: #fff8e6; padding: 15px; border-radius: 6px; border-left: 4px solid #ffa500;">
                    <p style="margin: 0; color: #666; font-size: 14px; line-height: 1.6;">{{ $bulkOrder->notes }}</p>
                </div>
            @endif

            <center>
                <a href="{{ $adminUrl }}" class="cta-button">Review Order in Admin Panel</a>
            </center>
        </div>
        
        <div class="footer">
            <p class="footer-text">
                This is an automated notification from {{ $company->name }}.
            </p>
            <p class="company-info">
                {{ $company->name }}<br>
                Email: {{ $company->email }}<br>
                Phone: {{ $company->phone }}
            </p>
        </div>
    </div>
</body>
</html>
