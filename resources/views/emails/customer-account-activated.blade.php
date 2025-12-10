<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Activated</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background: #28a745; color: #ffffff; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px; color: #155724; }
        .customer-info { background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .customer-info p { margin: 5px 0; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
        .button { display: inline-block; padding: 12px 30px; background: #28a745; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .icon { font-size: 48px; margin-bottom: 10px; }
        .features { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .features ul { margin: 10px 0; padding-left: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">✅</div>
            <h1>Welcome Back!</h1>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <div class="success-box">
                <strong>✅ Great News!</strong><br>
                Your account has been successfully activated and is now fully operational.
            </div>

            <div class="customer-info">
                <p><strong>Account Details:</strong></p>
                <p><strong>Name:</strong> {{ $customer->full_name }}</p>
                <p><strong>Email:</strong> {{ $customer->email }}</p>
                @if($customer->phone)
                <p><strong>Phone:</strong> {{ $customer->phone }}</p>
                @endif
            </div>

            <h3>Your Account is Now Active</h3>
            <p>You can now enjoy full access to all features and services:</p>

            <div class="features">
                <ul>
                    <li>✓ Browse and shop from our stores</li>
                    <li>✓ Place new orders</li>
                    <li>✓ Track your order history</li>
                    <li>✓ Manage your account settings</li>
                    <li>✓ Access exclusive deals and promotions</li>
                </ul>
            </div>

            <p style="text-align: center;">
                <a href="{{ config('app.url') }}" class="button">Start Shopping Now</a>
            </p>

            <h3>Need Help?</h3>
            <p>If you have any questions or need assistance, our support team is here to help:</p>
            <p>
                <strong>Contact Support:</strong><br>
                Email: {{ config('mail.from.address') }}<br>
                We're available to assist you anytime.
            </p>

            <p style="margin-top: 30px;">Thank you for being a valued customer. We're excited to serve you again!</p>
        </div>
        
        <div class="footer">
            <p><strong>{{ config('app.name') }}</strong></p>
            <p>This is an automated message. Please do not reply directly to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
