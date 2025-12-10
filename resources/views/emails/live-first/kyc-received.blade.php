<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live First Application Received</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .application-details { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #28a745; }
        .status-badge { display: inline-block; padding: 5px 15px; background: #ffc107; color: #000; border-radius: 20px; font-weight: bold; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .info-box { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Live First Application Received!</h1>
            <p>Your application is being processed</p>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <p>Thank you for applying to the <strong>Live First Program</strong>! We have successfully received your KYC application and our team is now reviewing your documents.</p>
            
            <div class="application-details">
                <h2>Application Details</h2>
                <p><strong>Application ID:</strong> {{ $kycId }}</p>
                <p><strong>Submitted On:</strong> {{ $application->submitted_at->format('F d, Y \a\t H:i A') }}</p>
                <p><strong>Status:</strong> <span class="status-badge">Under Review</span></p>
            </div>
            
            <div class="info-box">
                <h3>📋 What Happens Next?</h3>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>Our team will review your submitted documents within 2-3 business days</li>
                    <li>We may contact you if any additional information is needed</li>
                    <li>You will receive an email notification once your application is approved or if any action is required</li>
                    <li>Once approved, you can start shopping with the Live First program!</li>
                </ul>
            </div>
            
            <div class="application-details">
                <h3>Program Benefits</h3>
                <p>✅ <strong>Pay only 10%</strong> upfront on your purchases</p>
                <p>✅ <strong>Balance spread</strong> over 6 months via salary deduction</p>
                <p>✅ <strong>Shop now,</strong> pay later conveniently</p>
                <p>✅ <strong>No hidden fees</strong> or interest charges</p>
            </div>
            
            <p><strong>Need Help?</strong><br>
            If you have any questions about your application or the Live First program, please don't hesitate to contact our support team.</p>
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}" class="button">Visit Our Store</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
