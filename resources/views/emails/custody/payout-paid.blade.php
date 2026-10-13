<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Payout sent</title>
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
              <h2 style="margin:0 0 12px;font-size:18px;">Payout sent</h2>
              <p style="margin:0 0 12px;">Hello {{ $user->name }},</p>
              <p style="margin:0 0 16px;">
                We have sent a payout from your {{ config('app.name') }} wallet for
                <strong>{{ $business->name }}</strong>. The money has left us and is on its way to your bank.
              </p>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Amount sent</div>
                <div style="font-size:22px;font-weight:700;">{{ $currency }} {{ $amount }}</div>
                <div style="color:#6b7280;font-size:12px;margin-top:4px;">Sent {{ $paidAt }}</div>
              </div>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Paid to</div>
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;">
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Bank</td>
                    <td style="padding:3px 0;text-align:right;">{{ $bankName ?? 'Your bank account' }}{{ $bankCode ? ' ('.$bankCode.')' : '' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Account number</td>
                    <td style="padding:3px 0;text-align:right;">{{ $accountNumberMasked }}</td>
                  </tr>
                  @if($accountName)
                    <tr>
                      <td style="padding:3px 0;color:#6b7280;">Account name</td>
                      <td style="padding:3px 0;text-align:right;">{{ $accountName }}</td>
                    </tr>
                  @endif
                  @if($sales > 0)
                    <tr>
                      <td style="padding:3px 0;color:#6b7280;">Sales covered</td>
                      <td style="padding:3px 0;text-align:right;">{{ $sales }}</td>
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

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Left in your wallet</div>
                <div style="font-size:18px;font-weight:700;">{{ $currency }} {{ $remaining }}</div>
              </div>

              <p style="margin:0 0 12px;">
                How long it takes to appear is up to your bank, not to us — most transfers land within minutes, but
                some banks hold incoming credits until the next working day. Quote the reference above if you need to
                ask them about it.
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
