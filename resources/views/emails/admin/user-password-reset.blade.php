<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your password has been reset</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.06);">
          <tr>
            <td style="padding:28px 32px 0;text-align:center;">
              @if(!empty($company['logo']))
                <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}" style="height:28px;object-fit:contain;">
              @else
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company['name'] }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px 8px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">Your password has been reset</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:22px;">
                Hello {{ $user->name ?: 'there' }}, an administrator has reset the password for your account. Use the temporary password below to sign in — you will be asked to choose a new password right away.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:14px 18px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;">Email</div>
                    <div style="font-size:15px;font-weight:600;color:#0f172a;">{{ $user->email }}</div>
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-top:10px;">Temporary Password</div>
                    <div style="font-size:17px;font-weight:700;color:#0f172a;font-family:monospace;">{{ $temporaryPassword }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 24px;">
              <a href="{{ $loginUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:8px;">Sign In</a>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <p style="margin:0;color:#94a3b8;font-size:12px;line-height:18px;">
                If you did not request this change, contact support immediately at {{ $company['support_email'] }}.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
