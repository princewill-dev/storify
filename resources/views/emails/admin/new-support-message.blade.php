<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Support Message</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f5; padding: 40px 0;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1a1a1a 0%, #333333 100%); padding: 40px 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">New Support Message</h1>
                            <p style="margin: 10px 0 0; color: #cccccc; font-size: 14px;">Requires Your Attention</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <!-- Alert Badge -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 25px;">
                                <tr>
                                    <td style="background-color: #f0f0f0; border-left: 4px solid #333333; padding: 15px; border-radius: 4px;">
                                        <p style="margin: 0; color: #333333; font-size: 14px; font-weight: 600;">
                                            ⚡ New support message received from {{ $supportMessage->store->name }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Customer Information -->
                            <h3 style="margin: 0 0 15px; color: #333333; font-size: 18px; font-weight: 600; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px;">Customer Information</h3>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px; width: 120px;"><strong>Name:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">{{ $supportMessage->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px;"><strong>Email:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">{{ $supportMessage->email }}</td>
                                </tr>
                                @if($supportMessage->phone)
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px;"><strong>Phone:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">{{ $supportMessage->phone }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px;"><strong>Store:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">{{ $supportMessage->store->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px;"><strong>Date:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">{{ $supportMessage->created_at->format('M d, Y \a\t h:i A') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; color: #666666; font-size: 14px;"><strong>Message ID:</strong></td>
                                    <td style="padding: 8px 0; color: #333333; font-size: 14px;">#{{ $supportMessage->id }}</td>
                                </tr>
                            </table>

                            <!-- Message Content -->
                            <h3 style="margin: 0 0 15px; color: #333333; font-size: 18px; font-weight: 600; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px;">Message Content</h3>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fafafa; border-radius: 4px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0; color: #333333; font-size: 15px; line-height: 1.6; white-space: pre-wrap;">{{ $supportMessage->message }}</p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Action Button -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 20px 0;">
                                        <a href="{{ route('admin.support-messages.index') }}" style="display: inline-block; background-color: #333333; color: #ffffff; text-decoration: none; padding: 14px 40px; border-radius: 4px; font-size: 15px; font-weight: 600;">
                                            View & Reply in Dashboard
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #2c2c2c; padding: 30px; text-align: center; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px; color: #cccccc; font-size: 14px;">
                                {{ config('app.name') }} Admin Notification
                            </p>
                            <p style="margin: 0; color: #999999; font-size: 12px;">
                                Please respond to the customer within 24-48 hours.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Footer Text -->
                <table role="presentation" cellpadding="0" cellspacing="0" width="600">
                    <tr>
                        <td style="padding: 20px 0; text-align: center;">
                            <p style="margin: 0; color: #999999; font-size: 12px;">
                                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
