<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live First Application Approved!</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .success-box { background: #d4edda; border: 2px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 10px; text-align: center; }
        .benefits-box { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .benefit-item { padding: 15px; margin: 10px 0; background: #f8f9fa; border-left: 4px solid #28a745; border-radius: 3px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 15px 40px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-size: 16px; font-weight: bold; }
        .button:hover { background: #218838; }
        .checkmark { font-size: 60px; color: #28a745; }
        .highlight { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="checkmark">✓</div>
            <h1>Congratulations!</h1>
            <p style="font-size: 18px;">Your Live First Application Has Been Approved</p>
        </div>
        
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            
            <div class="success-box">
                <h2 style="color: #28a745; margin-top: 0;">🎉 You're All Set!</h2>
                <p style="font-size: 16px; margin-bottom: 0;">You can now start shopping with the Live First program and enjoy flexible payment options.</p>
            </div>
            
            <p>We're excited to inform you that your Live First KYC application (ID: <strong>{{ $kycId }}</strong>) has been <strong style="color: #28a745;">APPROVED</strong>!</p>
            
            <div class="benefits-box">
                <h3 style="color: #28a745; text-align: center;">🚀 Your Live First Benefits</h3>
                
                <div class="benefit-item">
                    <strong>💰 Pay Only 10% Upfront</strong>
                    <p style="margin: 5px 0 0 0; color: #666;">Make your purchase today with just a 10% down payment</p>
                </div>
                
                <div class="benefit-item">
                    <strong>📅 6-Month Payment Plan</strong>
                    <p style="margin: 5px 0 0 0; color: #666;">Balance automatically deducted from your salary over 6 months</p>
                </div>
                
                <div class="benefit-item">
                    <strong>🎯 No Hidden Fees</strong>
                    <p style="margin: 5px 0 0 0; color: #666;">Transparent pricing with no additional charges or interest</p>
                </div>
                
                <div class="benefit-item">
                    <strong>🛍️ Shop Immediately</strong>
                    <p style="margin: 5px 0 0 0; color: #666;">Start shopping right away with your new payment option</p>
                </div>
            </div>
            
            <div class="highlight">
                <h3>📝 How to Use Live First:</h3>
                <ol style="margin: 10px 0; padding-left: 20px;">
                    <li>Browse our store and add items to your cart</li>
                    <li>At checkout, select <strong>"Use Live First Program"</strong></li>
                    <li>Pay only 10% of your total order amount</li>
                    <li>The remaining 90% will be automatically deducted from your salary over 6 months</li>
                </ol>
            </div>
            
            <p style="text-align: center; margin-top: 30px;">
                <a href="{{ $appUrl }}" class="button">Start Shopping Now →</a>
            </p>
            
            <p style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <strong>Questions?</strong><br>
                If you have any questions about using the Live First program or need assistance, our support team is here to help!
            </p>
            
            <p style="color: #666; font-size: 14px; text-align: center; margin-top: 20px;">
                Welcome to a new way of shopping! 🎊
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>You're receiving this email because you applied for the Live First program.</p>
        </div>
    </div>
</body>
</html>
