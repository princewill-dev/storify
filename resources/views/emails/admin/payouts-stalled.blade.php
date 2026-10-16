<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payouts stopped for {{ $business->name ?? 'a business' }}</title>
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
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company->name ?? 'Admin Team' }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 4px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">Payouts have stopped for a business</h1>
            </td>
          </tr>
          {{--
              The reassurance comes before the detail on purpose. Every attempt
              was refused and every refusal was credited back, so the first thing
              an operator must be able to read is "no money is missing" — otherwise
              the first move is an incident call that isn't one, and the actual
              question ("what is refusing this?") gets answered second.

              Which reassurance is true depends on whether a transfer was ever
              made. With no `$payout` nothing was sent at all, so promising that
              "each refusal was credited back" would describe a refund that never
              happened.
          --}}
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">
                We have stopped trying to send money to
                <strong>{{ $business->name ?? 'business #'.$business->id }}</strong>
                after {{ $failures }} failed {{ \Illuminate\Support\Str::plural('attempt', $failures) }} in a row.
                @if($payout)
                  Nothing has been lost: each refusal returned the money to their wallet, where it is still owed
                  and still visible to them. Sending resumes when an operator retries from the payout queue.
                @else
                  Nothing has been lost and nothing was ever sent — the provider could not be reached, so the money
                  never left their wallet, where it is still owed and still visible to them. Sending resumes when
                  an operator retries from the payout queue.
                @endif
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 20px;">
              <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#0f172a;">
                  <tr>
                    <td style="padding:6px 0;width:170px;color:#64748b;">Business</td>
                    <td style="padding:6px 0;">{{ $business->name ?? '—' }}@if(!empty($business->business_code)) <span style="color:#64748b;">({{ $business->business_code }})</span>@endif</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:170px;color:#64748b;">Held in their wallet</td>
                    <td style="padding:6px 0;font-weight:600;">{{ $currency }} {{ $held }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:170px;color:#64748b;">Reason sending stopped</td>
                    <td style="padding:6px 0;">{{ $blocker['label'] ?? $blocker['code'] ?? 'unknown' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:170px;color:#64748b;">Failed attempts</td>
                    <td style="padding:6px 0;">{{ $failures }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:170px;color:#64748b;">Last attempt</td>
                    <td style="padding:6px 0;">{{ $payout?->reference ?? 'No transfer was created' }}</td>
                  </tr>
                  {{--
                      The provider's own words, quoted rather than summarised.
                      A paraphrase here would put a second opinion between the
                      operator and the only evidence of what actually happened.
                      No account number: mail is a channel this platform does not
                      control, and the console has the number behind a permission
                      and an audit row.

                      Guarded on `$payout` as well as on the fields: reading a
                      property of null warns rather than returning null, so
                      `!empty()` alone would not make this row safe.
                  --}}
                  @if($payout !== null && (!empty($payout->failure_code) || !empty($payout->failure_reason)))
                    <tr>
                      <td style="padding:6px 0;width:170px;color:#64748b;vertical-align:top;">Provider said</td>
                      <td style="padding:6px 0;">
                        @if(!empty($payout->failure_code))
                          <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;">{{ $payout->failure_code }}</div>
                        @endif
                        @if(!empty($payout->failure_reason))
                          <div style="color:#334155;">{{ $payout->failure_reason }}</div>
                        @endif
                      </td>
                    </tr>
                  @endif
                </table>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <a href="{{ $reviewUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:14px;">Open the payout queue</a>
              <p style="margin:10px 0 0;color:#64748b;font-size:12px;line-height:18px;">
                Retry sends it again immediately, without changing the amount or the account it goes to.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:12px 32px 28px;border-top:1px solid #e5e7eb;color:#64748b;font-size:12px;line-height:18px;">
              <div>{{ $company->name ?? '' }} • {{ $company->email ?? '' }} • {{ $company->phone ?? '' }}</div>
              <div>{{ $company->address ?? '' }}</div>
              @if(!empty($company->branch_address))
                <div>{{ $company->branch_address }}</div>
              @endif
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
