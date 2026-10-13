<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Payment received</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  @php($c = is_array($company ?? null) ? $company : (array) ($company ?? []))
  @php($amount = number_format($amountKobo / 100, 2))
  @php($pending = number_format($pendingKobo / 100, 2))
  @php($available = number_format($availableKobo / 100, 2))
  @php($total = number_format(($pendingKobo + $availableKobo) / 100, 2))
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td align="center" style="padding:24px;">
        <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
          <tr>
            <td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">
              @if(!empty($c['logo']))
                <img src="{{ $c['logo'] }}" alt="{{ $c['name'] ?? 'Company' }}" style="height:32px;object-fit:contain;vertical-align:middle;">
              @endif
              <strong style="font-size:16px;vertical-align:middle;">{{ $c['name'] ?? config('app.name') }}</strong>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <h2 style="margin:0 0 12px;font-size:18px;">Payment received</h2>
              <p style="margin:0 0 12px;">Hello {{ $user->name }},</p>
              <p style="margin:0 0 16px;">
                A payment for <strong>{{ $business->name }}</strong>{{ $storeName ? ' at '.$storeName : '' }} has been
                collected and your share has been added to your {{ config('app.name') }} wallet.
              </p>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Your share</div>
                <div style="font-size:22px;font-weight:700;">{{ $currency }} {{ $amount }}</div>
                @if($reference)
                  <div style="color:#6b7280;font-size:12px;margin-top:4px;">Transaction reference: {{ $reference }}</div>
                @endif
              </div>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Your wallet now</div>
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;">
                  <tr>
                    <td style="padding:3px 0;">Settling (not yet payable)</td>
                    <td style="padding:3px 0;text-align:right;">{{ $currency }} {{ $pending }}</td>
                  </tr>
                  <tr>
                    <td style="padding:3px 0;">Payable to your bank</td>
                    <td style="padding:3px 0;text-align:right;">{{ $currency }} {{ $available }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0 0;border-top:1px solid #e5e7eb;font-weight:600;">Wallet total</td>
                    <td style="padding:6px 0 0;border-top:1px solid #e5e7eb;text-align:right;font-weight:600;">{{ $currency }} {{ $total }}</td>
                  </tr>
                </table>
              </div>

              <p style="margin:0 0 12px;">
                "Settling" is money {{ config('app.name') }} has collected for you that the payment provider is still
                clearing. Once it clears it becomes payable automatically, and we send it to your payout bank account
                without you having to ask.
              </p>

              @php($supportEmail = $c['email'] ?? config('mail.from.address'))
              @if($supportEmail)
                <p style="margin:0 0 12px;">Questions? Contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
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
