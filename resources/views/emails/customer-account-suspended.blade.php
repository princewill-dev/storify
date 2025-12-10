<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Suspended</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background: #dc3545; color: #ffffff; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .reason-box { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .reason-box strong { display: block; margin-bottom: 10px; color: #495057; }
        .customer-info { background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .customer-info p { margin: 5px 0; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
        .button { display: inline-block; padding: 12px 30px; background: #007bff; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .icon { font-size: 48px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">⚠️</div>
            <h1>Account Suspended</h1>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <div class="alert">
                <strong>⚠️ Important Notice</strong><br>
                Your account has been temporarily suspended by our administration team.
            </div>

            <div class="customer-info">
                <p><strong>Account Details:</strong></p>
                <p><strong>Name:</strong> {{ $customer->full_name }}</p>
                <p><strong>Email:</strong> {{ $customer->email }}</p>
                @if($customer->phone)
                <p><strong>Phone:</strong> {{ $customer->phone }}</p>
                @endif
            </div>

            <div class="reason-box">
                <strong>Reason for Suspension:</strong>
                <p>{{ $reason }}</p>
            </div>

            <h3>What This Means:</h3>
            <ul>
                <li>You will not be able to place new orders</li>
                <li>You cannot access your account dashboard</li>
                <li>Existing orders will continue to be processed</li>
                <li>Your account data remains secure</li>
            </ul>

            <h3>What You Should Do:</h3>
            <p>If you believe this suspension was made in error or if you would like to discuss this matter, please contact our support team immediately.</p>

            <p style="margin-top: 30px;">
                <strong>Contact Support:</strong><br>
                Email: {{ config('mail.from.address') }}<br>
                We typically respond within 24 hours.
            </p>

            <p style="margin-top: 30px;">We take account security and policy compliance seriously. We appreciate your understanding and cooperation in this matter.</p>
        </div>
        
        <div class="footer">
            <p><strong>{{ config('app.name') }}</strong></p>
            <p>This is an automated message. Please do not reply directly to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
