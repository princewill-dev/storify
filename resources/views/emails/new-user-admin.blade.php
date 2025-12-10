<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New User Registration</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #673AB7; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .user-details { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #673AB7; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .alert { background: #e8eaf6; border-left: 4px solid #673AB7; padding: 15px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👤 New User Registration</h1>
        </div>
        
        <div class="content">
            <div class="alert">
                <strong>New User Alert:</strong> A new user has registered on your platform.
            </div>
            
            <div class="user-details">
                <h2>User Information</h2>
                <p><strong>Name:</strong> {{ $user->name }}</p>
                <p><strong>Email:</strong> {{ $user->email }}</p>
                <p><strong>Registration Date:</strong> {{ $user->created_at->format('F d, Y H:i:s') }}</p>
                <p><strong>User ID:</strong> {{ $user->id }}</p>
                @if($user->role)
                <p><strong>Role:</strong> {{ ucfirst($user->role) }}</p>
                @endif
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $adminUrl }}" class="button">View User in Admin Panel</a>
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>This is an automated notification from your e-commerce system.</p>
        </div>
    </div>
</body>
</html>
