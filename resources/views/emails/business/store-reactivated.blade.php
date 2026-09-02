<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Store Reactivated</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#0f172a;">
  @php($c = is_array($company ?? null) ? $company : (array) ($company ?? []))
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.06);">
          <tr>
            <td style="padding:28px 32px 0;text-align:center;">
              @if(!empty($c['logo']))
                <img src="{{ $c['logo'] }}" alt="{{ $c['name'] ?? 'Company' }}" style="height:28px;object-fit:contain;">
              @else
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $c['name'] ?? 'Company' }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 4px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">Store reactivated</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">Your store has been reactivated. If you have questions, please reply to this email.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 20px;">
              <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#0f172a;">
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Store</td>
                    <td style="padding:6px 0;">{{ $store->name }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Store ID</td>
                    <td style="padding:6px 0;"><code>{{ $store->store_id }}</code></td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">New status</td>
                    <td style="padding:6px 0;"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px;">{{ $store->status }}</span></td>
                  </tr>
                  @isset($reason)
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Notes</td>
                    <td style="padding:6px 0;white-space:pre-wrap;">{{ $reason }}</td>
                  </tr>
                  @endisset
                </table>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:12px 32px 28px;border-top:1px solid #e5e7eb;color:#64748b;font-size:12px;line-height:18px;">
              <div>{{ $c['name'] ?? 'Company' }} • {{ $c['email'] ?? '' }} • {{ $c['phone'] ?? '' }}</div>
              <div>{{ $c['address'] ?? '' }}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
