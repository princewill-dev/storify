<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; border-collapse: collapse; background-color: #ffffff; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 40px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">Password Reset</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #333333;">
                                Hello {{ $user->name }},
                            </p>
                            
                            <p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #333333;">
                                We received a request to reset your password. Use the OTP code below to proceed with resetting your password:
                            </p>
                            
                            <!-- OTP Box -->
                            <table role="presentation" style="width: 100%; margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 8px; display: inline-block;">
                                            <p style="margin: 0; color: #ffffff; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Your OTP Code</p>
                                            <p style="margin: 10px 0 0; color: #ffffff; font-size: 36px; font-weight: bold; letter-spacing: 8px; font-family: 'Courier New', monospace;">
                                                {{ $otp }}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #333333;">
                                This code will expire in <strong>10 minutes</strong>. If you didn't request a password reset, please ignore this email or contact support if you have concerns.
                            </p>
                            
                            <div style="margin: 30px 0; padding: 20px; background-color: #f8f9fa; border-left: 4px solid #667eea; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #666666;">
                                    <strong>Security Tip:</strong> Never share your OTP code with anyone. Our team will never ask for your OTP.
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; background-color: #f8f9fa; text-align: center; border-top: 1px solid #e9ecef;">
                            <p style="margin: 0 0 10px; font-size: 14px; color: #666666;">
                                Best regards,<br>
                                <strong>{{ config('app.name') }} Team</strong>
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #999999;">
                                This is an automated email. Please do not reply to this message.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
