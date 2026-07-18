<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply to Your Support Message</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f5; padding: 40px 0;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #2c2c2c 0%, #4a4a4a 100%); padding: 40px 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">We've Replied to Your Message</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.6;">
                                Dear <strong>{{ $supportMessage->name }}</strong>,
                            </p>

                            <p style="margin: 0 0 25px; color: #555555; font-size: 15px; line-height: 1.6;">
                                Thank you for your patience. We have reviewed your message and here is our response:
                            </p>

                            <!-- Your Original Message -->
                            <h3 style="margin: 0 0 15px; color: #666666; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Your Original Message</h3>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8f8f8; border-left: 4px solid #cccccc; border-radius: 4px; margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0; color: #555555; font-size: 14px; line-height: 1.6; white-space: pre-wrap;">
                                            {{ $supportMessage->message }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Our Response -->
                            <h3 style="margin: 0 0 15px; color: #333333; font-size: 18px; font-weight: 600; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px;">Our Response</h3>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fafafa; border-left: 4px solid #333333; border-radius: 4px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0; color: #333333; font-size: 15px; line-height: 1.6; white-space: pre-wrap;">
                                            {{ $supportMessage->reply }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Reply Info -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f0f0f0; border-radius: 4px; margin: 25px 0;">
                                <tr>
                                    <td style="padding: 15px;">
                                        <p style="margin: 0; color: #666666; font-size: 13px;">
                                            <strong>Replied by:</strong> {{ ucfirst($supportMessage->replied_by_type) }} Team<br>
                                            <strong>Reply Date:</strong> {{ $supportMessage->replied_at->format('M d, Y \a\t h:i A') }}<br>
                                            <strong>Reference ID:</strong> #{{ $supportMessage->id }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 20px; color: #555555; font-size: 15px; line-height: 1.6;">
                                If you have any further questions or need additional assistance, please don't hesitate to reach out to us again.
                            </p>

                            <!-- Contact Info -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-top: 30px;">
                                <tr>
                                    <td>
                                        <p style="margin: 0 0 10px; color: #666666; font-size: 14px;">
                                            <strong>Store:</strong> {{ $supportMessage->store?->name ?? '—' }}
                                        </p>
                                        @if($supportMessage->store->support_email)
                                        <p style="margin: 0 0 10px; color: #666666; font-size: 14px;">
                                            <strong>Email:</strong> {{ $supportMessage->store->support_email }}
                                        </p>
                                        @endif
                                        @if($supportMessage->store->support_phone)
                                        <p style="margin: 0; color: #666666; font-size: 14px;">
                                            <strong>Phone:</strong> {{ $supportMessage->store->support_phone }}
                                        </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #2c2c2c; padding: 30px; text-align: center; border-radius: 0 0 8px 8px;">
                            <p style="margin: 0 0 10px; color: #cccccc; font-size: 14px;">
                                Thank you for choosing {{ config('app.name') }}
                            </p>
                            <p style="margin: 0; color: #999999; font-size: 12px;">
                                We're here to help you with any questions or concerns.
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
