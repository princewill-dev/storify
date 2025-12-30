<!DOCTYPE html>
<html>
<head>
    <title>We received your message</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2c3e50;">We received your message</h2>
        <p>Dear {{ $data['name'] }},</p>
        <p>Thanks for reaching out to us. This is a confirmation that we have received your message regarding "<strong>{{ $data['subject'] }}</strong>".</p>
        
        <p>Our team will review your inquiry and get back to you shortly.</p>
        
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <p><strong>Your Message:</strong></p>
            <p style="white-space: pre-wrap;">{{ $data['message'] }}</p>
        </div>
        
        <p>Best regards,<br>The Support Team</p>
    </div>
</body>
</html>
