<!DOCTYPE html>
<html>
<head>
    <title>New Support Message</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2c3e50;">New Support Message</h2>
        <p>A new support message has been received from your website.</p>
        
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <p><strong>Name:</strong> {{ $data['name'] }}</p>
            <p><strong>Email:</strong> {{ $data['email'] }}</p>
            <p><strong>Phone:</strong> {{ $data['phone'] ?? 'N/A' }}</p>
            <p><strong>Subject:</strong> {{ $data['subject'] }}</p>
            
            <hr style="border: 1px solid #eee; margin: 15px 0;">
            
            <p><strong>Message:</strong></p>
            <p style="white-space: pre-wrap;">{{ $data['message'] }}</p>
        </div>
        
        <p style="font-size: 12px; color: #7f8c8d;">This email was sent automatically from your website contact form.</p>
    </div>
</body>
</html>
