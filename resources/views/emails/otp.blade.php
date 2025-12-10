<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Code</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            padding: 30px 40px;
            background-color: #ffffff;
            border-bottom: 1px solid #e5e5e5;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo-icon {
            width: 32px;
            height: 32px;
            background-color: #00D084;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: 600;
            color: #000000;
        }
        .email-body {
            padding: 40px 40px 30px;
        }
        .greeting {
            color: #1a1a1a;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .otp-container {
            background-color: #e6f9f3;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }
        .otp-code {
            font-size: 42px;
            font-weight: 700;
            color: #000000;
            letter-spacing: 8px;
            margin: 0;
        }
        .warning-text {
            color: #666666;
            font-size: 14px;
            line-height: 1.6;
            margin: 20px 0;
        }
        .footer {
            padding: 30px 40px;
            background-color: #fafafa;
            border-top: 1px solid #e5e5e5;
        }
        .footer-text {
            color: #666666;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }
        .footer-link {
            color: #00D084;
            text-decoration: none;
        }
        .company-info {
            color: #999999;
            font-size: 12px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="logo">
                <!-- <div class="logo-icon">E</div> -->
                <div class="logo-text">{{ $company->name }}</div>
            </div>
        </div>
        
        <div class="email-body">
            <p class="greeting">Your {{ $appName }} verification code is:</p>
            
            <div class="otp-container">
                <h1 class="otp-code">{{ $otpCode }}</h1>
            </div>
            
            <p class="warning-text">
                This code will expire in {{ $expiryMinutes }} minutes and can only be used once. Never share this code with anyone.
            </p>
        </div>
        
        <div class="footer">
            <p class="footer-text">
                If you believe you are getting this email in error or want to close your {{ $appName }} account, please visit our 
                <a href="{{ $supportUrl }}" class="footer-link">support site</a>. 
                To learn more about {{ $appName }}, please visit 
                <a href="{{ $appUrl }}" class="footer-link">{{ $appDomain }}</a>.
            </p>
            <p class="company-info">
                {{ $appName }}<br>
                {{ $companyAddress }}
            </p>
        </div>
    </div>
</body>
</html>
