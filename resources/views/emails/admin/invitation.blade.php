<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>You have been invited to the Storify admin team</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.06);">
          <tr>
            <td style="padding:28px 32px 0;text-align:center;">
              @if(!empty($company->logo))
                <img src="{{ $company->logo }}" alt="{{ $company->name }}" style="height:28px;object-fit:contain;">
              @else
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company->name ?? 'Storify' }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px 8px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">You have been invited</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:22px;">
                You have been invited to join the Storify platform admin team. Click the button below to accept the invitation and set up your account.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 24px;">
              <a href="{{ $acceptUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:8px;">Accept Invitation</a>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 24px;">
              <p style="margin:0;color:#64748b;font-size:12px;line-height:18px;">
                If the button does not work, copy and paste this link into your browser:<br>
                <span style="color:#4f46e5;word-break:break-all;">{{ $acceptUrl }}</span>
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 24px;">
              <p style="margin:0;color:#94a3b8;font-size:12px;line-height:18px;">
                This invitation link is unique to you and should not be shared. If you did not expect this invitation, you can safely ignore this email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
