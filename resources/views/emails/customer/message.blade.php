<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message from your store</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .content { padding: 30px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
        .body-text { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>

            <div class="body-text">{{ $message_body }}</div>
        </div>

        <div class="footer">
            <p><strong>{{ $business_name }}</strong></p>
            @if($show_opt_out)
                <p>
                    You are receiving this because you agreed to hear about news and offers
                    from {{ $business_name }}. You can change that at any time under
                    Account &rarr; Preferences.
                </p>
            @endif
        </div>
    </div>
</body>
</html>
