<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Vendor Account Suspended</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  @php($c = is_array($company ?? null) ? $company : (array) ($company ?? []))
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td align="center" style="padding:24px;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
          <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;">
              @if(!empty($c['logo']))
                <img src="{{ $c['logo'] }}" alt="{{ $c['name'] ?? 'Company' }}" style="height:32px;object-fit:contain;">
              @endif
              <strong style="font-size:16px;">{{ $c['name'] ?? config('app.name') }}</strong>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <h2 style="margin:0 0 12px;font-size:18px;">Vendor Account Suspended</h2>
              <p style="margin:0 0 12px;">Hello {{ $user->name }},</p>
              <p style="margin:0 0 16px;">Your vendor account has been suspended. See details below:</p>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Reason</div>
                <div style="white-space:pre-wrap;">{{ $reason }}</div>
              </div>

              <p style="margin:0 0 12px;">If you believe this was a mistake or after addressing the issue, please reply to this email so our team can review and assist you further.</p>

              @php($supportEmail = $c['email'] ?? config('mail.from.address'))
              @if($supportEmail)
                <p style="margin:0 0 12px;">Contact: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">
              <div>{{ $c['name'] ?? '' }} {{ !empty($c['email']) ? '• '.$c['email'] : '' }} {{ !empty($c['phone']) ? '• '.$c['phone'] : '' }}</div>
              <div>{{ $c['address'] ?? '' }}</div>
              @if(!empty($c['branch_address']))
                <div>{{ $c['branch_address'] }}</div>
              @endif
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
