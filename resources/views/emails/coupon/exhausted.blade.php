<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 40px 20px;">
    <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden;">
        <div style="padding: 32px 40px; border-bottom: 1px solid #f1f5f9; background: #0f172a;">
            <h1 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">Coupon Fully Redeemed</h1>
        </div>
        <div style="padding: 32px 40px;">
            <p style="margin: 0 0 16px; font-size: 15px; color: #334155; line-height: 1.6;">
                Coupon code <strong style="font-family: monospace; background: #f1f5f9; padding: 2px 8px; border-radius: 6px;">{{ $coupon->code }}</strong>
                {{ $coupon->name ? '(' . $coupon->name . ')' : '' }} has reached its usage limit.
            </p>
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px;">
                <tr>
                    <td style="padding: 10px 0; color: #64748b; border-bottom: 1px solid #f1f5f9;">Total Uses</td>
                    <td style="padding: 10px 0; text-align: right; font-weight: 600; color: #0f172a; border-bottom: 1px solid #f1f5f9;">{{ $coupon->uses_count }} / {{ $coupon->max_uses }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #64748b; border-bottom: 1px solid #f1f5f9;">Discount</td>
                    <td style="padding: 10px 0; text-align: right; font-weight: 600; color: #0f172a; border-bottom: 1px solid #f1f5f9;">{{ $coupon->discount_label }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #64748b;">Plan</td>
                    <td style="padding: 10px 0; text-align: right; font-weight: 600; color: #0f172a;">{{ $coupon->subscriptionPlan?->name ?? 'All Plans' }}</td>
                </tr>
            </table>
            <p style="margin: 24px 0 0; font-size: 13px; color: #94a3b8;">
                The coupon has been automatically marked as inactive. No further action is required.
            </p>
        </div>
        <div style="padding: 20px 40px; background: #f8fafc; font-size: 12px; color: #94a3b8;">
            {{ config('app.name') }} · Coupon notifications
        </div>
    </div>
</body>
</html>
