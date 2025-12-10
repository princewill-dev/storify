<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #9C27B0; color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .welcome-box { background: white; padding: 25px; margin: 20px 0; border-radius: 5px; text-align: center; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 15px 40px; background: #9C27B0; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-size: 16px; }
        .features { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .feature-item { padding: 10px 0; border-bottom: 1px solid #eee; }
        .feature-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Welcome to {{ $appName }}!</h1>
        </div>
        
        <div class="content">
            <div class="welcome-box">
                <h2>Hello {{ $user->name }}!</h2>
                <p style="font-size: 18px;">Thank you for joining us. We're excited to have you on board!</p>
            </div>
            
            <p>Your account has been successfully created. You can now enjoy all the benefits of being a member:</p>
            
            <div class="features">
                <div class="feature-item">
                    <strong>✓ Fast Checkout</strong><br>
                    Save your details for quicker purchases
                </div>
                <div class="feature-item">
                    <strong>✓ Order Tracking</strong><br>
                    Track your orders in real-time
                </div>
                <div class="feature-item">
                    <strong>✓ Order History</strong><br>
                    View all your past orders
                </div>
                <div class="feature-item">
                    <strong>✓ Exclusive Offers</strong><br>
                    Get notified about special deals
                </div>
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $loginUrl }}" class="button">Start Shopping</a>
            </p>
            
            <p style="text-align: center; color: #666;">
                <strong>Your Account Details:</strong><br>
                Email: {{ $user->email }}
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>If you didn't create this account, please contact our support team immediately.</p>
        </div>
    </div>
</body>
</html>
