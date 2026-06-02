<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Expiring Soon</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 30px; }
        .highlight-box { background: white; padding: 25px; margin: 20px 0; border-radius: 8px; text-align: center; border-left: 4px solid #f59e0b; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 15px 40px; background: #111827; color: white; text-decoration: none; border-radius: 8px; margin: 20px 0; font-size: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏰ Trial Expiring Soon</h1>
        </div>

        <div class="content">
            <p>Hello {{ $subscription->vendor->name }},</p>

            <div class="highlight-box">
                @if($daysRemaining > 0)
                    <h2 style="margin: 0; color: #f59e0b;">{{ $daysRemaining }} day{{ $daysRemaining > 1 ? 's' : '' }} remaining</h2>
                    <p style="margin: 10px 0 0; color: #666;">Your free trial ends on {{ $subscription->expires_at->format('M d, Y') }}</p>
                @else
                    <h2 style="margin: 0; color: #ef4444;">Your trial expires today!</h2>
                    <p style="margin: 10px 0 0; color: #666;">Your store will go offline tomorrow if you don't upgrade</p>
                @endif
            </div>

            <p>To keep your store online and continue accepting orders, upgrade to a paid plan today.</p>

            <p>Here's what you'll lose if your trial expires:</p>
            <ul>
                <li>Your store will go <strong>offline</strong></li>
                <li>Customers won't be able to place orders</li>
                <li>Your product listings will be hidden</li>
            </ul>

            <p style="text-align: center;">
                <a href="{{ route('management.subscription.plan', ['vendor' => $subscription->vendor]) }}" class="button">Upgrade Now</a>
            </p>

            <p style="color: #666; font-size: 14px;">If you have any questions, our support team is happy to help.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
