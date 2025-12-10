<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Order Under Review</title>
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
            background-color: #fff8e6;
            border-left: 4px solid #ffa500;
            border-radius: 4px;
            padding: 20px;
            margin: 30px 0;
        }
        .status-title {
            font-size: 18px;
            font-weight: 600;
            color: #000000;
            margin: 0 0 10px 0;
        }
        .order-code {
            font-size: 24px;
            font-weight: 700;
            color: #00D084;
            margin: 10px 0;
        }
        .info-text {
            color: #666666;
            font-size: 14px;
            line-height: 1.6;
            margin: 15px 0;
        }
        .details-box {
            background-color: #f9f9f9;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .details-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
        }
        .details-row:last-child {
            border-bottom: none;
        }
        .details-label {
            color: #666666;
            font-size: 14px;
        }
        .details-value {
            color: #000000;
            font-weight: 600;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            padding: 14px 28px;
            background-color: #00D084;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
            margin: 20px 0;
        }
        .steps-list {
            margin: 20px 0;
            padding-left: 20px;
        }
        .steps-list li {
            color: #666666;
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 8px;
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
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="logo-text">{{ $company->name }}</div>
        </div>
        
        <div class="email-body">
            <p class="greeting">Hi {{ $customer->first_name }},</p>
            
            <div class="status-container">
                <p class="status-title">✓ Your Bulk Order Has Been Received</p>
                <p class="order-code">{{ $bulkOrder->bulk_code }}</p>
                <p style="margin: 0; color: #666; font-size: 14px;">Status: Under Review</p>
            </div>
            
            <p class="info-text">
                Thank you for submitting your bulk order! Our team is currently reviewing your request to ensure product availability and confirm pricing.
            </p>
            
            <div class="details-box">
                <div class="details-row">
                    <span class="details-label">Order Date:</span>
                    <span class="details-value">{{ $bulkOrder->created_at->format('M d, Y') }}</span>
                </div>
                <div class="details-row">
                    <span class="details-label">Items:</span>
                    <span class="details-value">{{ $items->count() }} product(s)</span>
                </div>
                <div class="details-row">
                    <span class="details-label">Estimated Total:</span>
                    <span class="details-value">₦{{ number_format($bulkOrder->estimated_total, 2) }}</span>
                </div>
                <div class="details-row">
                    <span class="details-label">Delivery To:</span>
                    <span class="details-value">{{ $deliveryAddress->deliveryRoute->area ?? 'N/A' }}</span>
                </div>
            </div>

            <p class="info-text"><strong>What happens next?</strong></p>
            <ol class="steps-list">
                <li>Our team will review your order within 24-48 hours</li>
                <li>We'll confirm product availability and final pricing</li>
                <li>You'll receive a payment link via email once approved</li>
                <li>Your order will be processed immediately after payment</li>
            </ol>

            <center>
                <a href="{{ $appUrl }}" class="cta-button">Track Order Status</a>
            </center>

            <p class="info-text">
                If you have any questions about your order, please don't hesitate to contact us.
            </p>
        </div>
        
        <div class="footer">
            <p class="footer-text">
                Need help? Visit our <a href="{{ $supportUrl }}" class="footer-link">support center</a> or 
                contact us at <a href="mailto:{{ $company->email }}" class="footer-link">{{ $company->email }}</a>.
            </p>
            <p class="company-info">
                {{ $company->name }}<br>
                Email: {{ $company->email }}<br>
                Phone: {{ $company->phone }}<br>
                {{ $company->address }}
            </p>
        </div>
    </div>
</body>
</html>
