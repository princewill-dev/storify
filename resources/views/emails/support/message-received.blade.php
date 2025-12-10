<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Message Received</title>
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
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">Message Received</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px; color: #333333; font-size: 16px; line-height: 1.6;">
                                Dear <strong>{{ $supportMessage->name }}</strong>,
                            </p>

                            <p style="margin: 0 0 20px; color: #555555; font-size: 15px; line-height: 1.6;">
                                Thank you for reaching out to us. We have received your support message and our team will review it shortly.
                            </p>

                            <!-- Message Details Box -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8f8f8; border-left: 4px solid #666666; border-radius: 4px; margin: 25px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0 0 10px; color: #666666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Your Message</p>
                                        <p style="margin: 0; color: #333333; font-size: 14px; line-height: 1.6;">
                                            {{ $supportMessage->message }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 20px; color: #555555; font-size: 15px; line-height: 1.6;">
                                You can expect a response from us within 24-48 hours. We appreciate your patience.
                            </p>

                            <!-- Info Box -->
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fafafa; border-radius: 4px; margin: 25px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <p style="margin: 0 0 8px; color: #666666; font-size: 13px;"><strong>Reference ID:</strong> #{{ $supportMessage->id }}</p>
                                        <p style="margin: 0 0 8px; color: #666666; font-size: 13px;"><strong>Store:</strong> {{ $supportMessage->store->name }}</p>
                                        <p style="margin: 0; color: #666666; font-size: 13px;"><strong>Submitted:</strong> {{ $supportMessage->created_at->format('M d, Y \a\t h:i A') }}</p>
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
                                This is an automated message, please do not reply to this email.
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
