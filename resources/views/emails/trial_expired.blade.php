<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Expired</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #ef4444; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 30px; }
        .alert-box { background: #fef2f2; padding: 25px; margin: 20px 0; border-radius: 8px; text-align: center; border: 1px solid #fecaca; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 15px 40px; background: #111827; color: white; text-decoration: none; border-radius: 8px; margin: 20px 0; font-size: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔴 Free Trial Expired</h1>
        </div>

        <div class="content">
            <p>Hello {{ $subscription->vendor->name }},</p>

            <div class="alert-box">
                <h2 style="margin: 0; color: #ef4444;">Your free trial has ended</h2>
                <p style="margin: 10px 0 0; color: #666;">Your store is now <strong>offline</strong> and not visible to customers.</p>
            </div>

            <p>Don't worry — your store data, products, and settings are all still saved. You can reactivate your store instantly by subscribing to a paid plan.</p>

            <p><strong>What happened:</strong></p>
            <ul>
                <li>Your store status has been changed to <strong>pending</strong></li>
                <li>Customers can no longer visit your store or place orders</li>
                <li>Your products are no longer visible in search results</li>
            </ul>

            <p><strong>To get back online:</strong></p>
            <p>Simply log in and choose a subscription plan to reactivate your store immediately.</p>

            <p style="text-align: center;">
                <a href="{{ route('management.subscription.plan', ['vendor' => $subscription->vendor]) }}" class="button">Reactivate My Store</a>
            </p>

            <p style="color: #666; font-size: 14px;">Need help? Our support team is here for you.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
