<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status Update</title>
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
            background-color: #ffffff;
            border-bottom: 1px solid #e5e5e5;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: 600;
            color: #000000;
        }
        .email-body {
            padding: 40px 40px 30px;
        }
        .greeting {
            color: #1a1a1a;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .status-container {
            background-color: #e6f9f3;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }
        .status-title {
            color: #666666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .status-text {
            font-size: 32px;
            font-weight: 700;
            color: #00D084;
            margin: 0;
        }
        .order-details {
            background-color: #fafafa;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .order-number {
            font-size: 18px;
            font-weight: 600;
            color: #000000;
            margin-bottom: 15px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #666666;
            font-size: 14px;
        }
        .detail-value {
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        .items-table th {
            background-color: #fafafa;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            color: #666666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 14px;
            color: #1a1a1a;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .view-order-btn {
            display: inline-block;
            padding: 14px 32px;
            background-color: #00D084;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
        }
        .info-text {
            color: #666666;
            font-size: 14px;
            line-height: 1.6;
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
        .footer-link {
            color: #00D084;
            text-decoration: none;
        }
        .company-info {
            color: #999999;
            font-size: 12px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="logo">
                <div class="logo-text">{{ $company->name }}</div>
            </div>
        </div>
        
        <div class="email-body">
            <p class="greeting">Hello {{ $customer->first_name }},</p>
            
            <p class="info-text">
                Your order status has been updated. Here are the details:
            </p>
            
            <div class="status-container">
                <div class="status-title">Current Status</div>
                <h1 class="status-text">{{ $newStatus }}</h1>
            </div>
            
            <div class="order-details">
                <div class="order-number">Order #{{ $order->order_number }}</div>
                
                <div class="detail-row">
                    <span class="detail-label">Order Date</span>
                    <span class="detail-value">{{ $order->created_at->format('M d, Y') }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Total Amount</span>
                    <span class="detail-value">₦{{ number_format($order->total, 2) }}</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Payment Status</span>
                    <span class="detail-value">{{ $order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status) }}</span>
                </div>
                
                @if($order->delivery_state && $order->delivery_area)
                <div class="detail-row">
                    <span class="detail-label">Delivery Location</span>
                    <span class="detail-value">{{ $order->delivery_area }}, {{ $order->delivery_state }}</span>
                </div>
                @endif
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td style="text-align: center;">{{ $item->quantity }}</td>
                        <td style="text-align: right;">₦{{ number_format($item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            
            <div class="button-container">
                <a href="{{ $appUrl }}/orders/{{ $order->id }}" class="view-order-btn">View Order Details</a>
            </div>
            
            <p class="info-text">
                If you have any questions about your order, please don't hesitate to contact our support team.
            </p>
        </div>
        
        <div class="footer">
            <p class="footer-text">
                For support or inquiries, please contact us at 
                <a href="mailto:{{ $company->email }}" class="footer-link">{{ $company->email }}</a>
                @if($company->phone)
                or call {{ $company->phone }}
                @endif.
            </p>
            <p class="company-info">
                {{ $company->name }}<br>
                @if($company->address)
                {{ $company->address }}<br>
                @endif
                @if($company->branch_address)
                {{ $company->branch_address }}
                @endif
            </p>
        </div>
    </div>
</body>
</html>
