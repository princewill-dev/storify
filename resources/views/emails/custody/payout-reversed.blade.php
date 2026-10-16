<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Payout returned</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  @php($c = is_array($company ?? null) ? $company : (array) ($company ?? []))
  @php($currency = $payout->currency ?: config('custody.currency', 'NGN'))
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
              <h2 style="margin:0 0 12px;font-size:18px;">Payout returned</h2>
              <p style="margin:0 0 12px;">Hello {{ $user->name }},</p>

              <p style="margin:0 0 16px;">
                A payout we sent for <strong>{{ $business->name }}</strong> has been sent back to us by the bank. It
                did leave us and reach the banking network, so if you saw it arrive and then leave again, that is why.
              </p>

              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:4px;">You are still owed this money.</div>
                <div>It is back in your {{ config('app.name') }} wallet and will be sent again automatically.</div>
              </div>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">The transfer that came back</div>
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;">
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Amount</td>
                    <td style="padding:3px 0;text-align:right;font-weight:600;">{{ $currency }} {{ $amount }}</td>
                  </tr>
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Bank</td>
                    <td style="padding:3px 0;text-align:right;">{{ $bankName ?? 'Your bank account' }}</td>
                  </tr>
                  @if($accountName)
                    <tr>
                      <td style="padding:3px 0;color:#6b7280;">Account name</td>
                      <td style="padding:3px 0;text-align:right;">{{ $accountName }}</td>
                    </tr>
                  @endif
                  @if($reference)
                    <tr>
                      <td style="padding:3px 0;color:#6b7280;">Reference</td>
                      <td style="padding:3px 0;text-align:right;">{{ $reference }}</td>
                    </tr>
                  @endif
                </table>
              </div>

              @if($reason)
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                  <div style="font-weight:600;margin-bottom:6px;">What the bank said</div>
                  <div style="white-space:pre-wrap;">{{ $reason }}</div>
                </div>
              @endif

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Your wallet now</div>
                <div style="font-size:18px;font-weight:700;">{{ $currency }} {{ $wallet }}</div>
              </div>

              <p style="margin:0 0 12px;">
                A returned transfer often means the receiving account could not take the funds rather than that
                anything is wrong with your details. We will simply try again, so please check your payout account
                is the one you want now — otherwise we may send it, have it returned, and send it once more.
              </p>

              @php($supportEmail = $c['email'] ?? config('mail.from.address'))
              @if($supportEmail)
                <p style="margin:0 0 12px;">Contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
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
