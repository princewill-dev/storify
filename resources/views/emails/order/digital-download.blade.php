<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your download is ready</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.06);">
          <tr>
            <td style="padding:28px 32px 0;">
              <div style="font-weight:700;font-size:18px;color:#111827;">{{ $store?->name ?? $appName }}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px 4px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">Your download is ready</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:22px;">
                Thank you for your purchase{{ $customer?->first_name ? ', '.$customer->first_name : '' }}. Your payment for order <strong>{{ $order->order_number }}</strong> has been confirmed. Use the button(s) below to download your item(s).
              </p>
            </td>
          </tr>

          @foreach($downloads as $download)
          <tr>
            <td style="padding:8px 32px 16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                <tr>
                  <td style="padding:16px 18px;">
                    <div style="font-size:15px;font-weight:700;color:#0f172a;">{{ $download['product_name'] }}</div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                      {{ $download['file_count'] }} file(s) &middot; up to {{ $download['downloads_remaining'] }} download(s)
                      @if($download['expires_at'])
                        &middot; link expires {{ $download['expires_at']->format('d M Y') }}
                      @endif
                    </div>
                    <div style="margin-top:14px;">
                      <a href="{{ $download['url'] }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:8px;">Download now</a>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          @endforeach

          <tr>
            <td style="padding:4px 32px 24px;">
              <p style="margin:0;color:#64748b;font-size:12px;line-height:18px;">
                Keep this email safe — the download link is unique to your purchase and cannot be shared. If the button does not work, copy and paste the link into your browser.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <p style="margin:0;color:#94a3b8;font-size:12px;line-height:18px;">
                Need help? Reply to this email or contact {{ $store?->support_email ?? config('mail.from.address') }}.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
