<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>New login detected</title>
</head>
<body style="font-family: system-ui, 'Segoe UI', sans-serif; line-height: 1.6;">
  <h1 style="margin-bottom: 0.5rem;">New login detected</h1>

  <p>Hey {{ $user->name }},</p>

  <p>We detected a login to your vendor account. Here are the details we captured:</p>

  <ul>
    <li><strong>Time:</strong> {{ now()->toDayDateTimeString() }}</li>
    <li><strong>IP address:</strong> {{ $ipAddress }}</li>
    <li><strong>Browser/Device:</strong> {{ $userAgent }}</li>
  </ul>

  <p>If this was you, you can safely ignore this email. If you suspect anything suspicious, please reset your password or contact support.</p>

  <p>Thanks,<br>{{ config('app.name') }} Team</p>
</body>
</html>
