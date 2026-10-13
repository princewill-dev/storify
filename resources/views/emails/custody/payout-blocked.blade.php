<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Action needed — we cannot send your payouts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  @php($c = is_array($company ?? null) ? $company : (array) ($company ?? []))
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
              <h2 style="margin:0 0 12px;font-size:18px;">Action needed — we cannot send your payouts</h2>
              <p style="margin:0 0 12px;">Hello {{ $user->name }},</p>

              <p style="margin:0 0 16px;">
                We have now tried {{ $attempts }} {{ $attempts === 1 ? 'time' : 'times' }} to send money for
                <strong>{{ $business->name }}</strong> to the bank account you have on file, and it has been refused
                every time. Rather than keep asking, we have stopped. Your money is safe and untouched — but it
                cannot reach you until the account is corrected.
              </p>

              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">Waiting to be paid to you</div>
                <div style="font-size:22px;font-weight:700;">{{ $currency }} {{ $wallet }}</div>
                <div style="color:#6b7280;font-size:12px;margin-top:4px;">
                  Held in your {{ config('app.name') }} wallet. It will be sent as soon as this is fixed.
                </div>
              </div>

              <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-weight:600;margin-bottom:6px;">The account we are paying to</div>
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;">
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Bank</td>
                    <td style="padding:3px 0;text-align:right;">{{ $bankName ?? 'Not on file' }}{{ $bankCode ? ' ('.$bankCode.')' : '' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Account number</td>
                    <td style="padding:3px 0;text-align:right;">{{ $accountNumberMasked ?: 'Not on file' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:3px 0;color:#6b7280;">Account name</td>
                    <td style="padding:3px 0;text-align:right;">{{ $accountName ?: 'Not on file' }}</td>
                  </tr>
                </table>
              </div>

              @if($reason)
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;margin-bottom:16px;">
                  <div style="font-weight:600;margin-bottom:6px;">What the bank keeps saying</div>
                  <div style="white-space:pre-wrap;">{{ $reason }}</div>
                </div>
              @endif

              <p style="margin:0 0 12px;">
                This usually means the account number is wrong, the account is closed or cannot receive transfers,
                or the account name does not match what the bank holds. Whatever the cause, please check your payout
                account in your dashboard and update it — the details above are the ones failing.
              </p>

              <p style="margin:0 0 12px;">
                Once you have updated it, reply to this email or contact our team so we can confirm the new account
                and restart your payouts. Until then, no money will leave your wallet.
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
