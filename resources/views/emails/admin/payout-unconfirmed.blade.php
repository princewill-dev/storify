<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payout unconfirmed for {{ $business->name ?? 'a business' }}</title>
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
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">A transfer is out and the provider has not confirmed it</h1>
            </td>
          </tr>
          {{--
              The instruction comes before the detail, and it is the opposite of the
              instruction on `payouts-stalled`. There, a refusal means the money came
              back and retrying is the correct next move; here the transfer may well
              have landed, so a resend is how a business gets paid twice. Nothing in
              this mail may read as "nothing was lost" or offer a retry, because
              neither is true and the second is expensive.

              The amount and the reference are the two facts an operator needs to find
              this transfer in the provider's own dashboard, which is why they are in
              the box below rather than only in the subject.
          --}}
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0 0 12px;color:#334155;font-size:14px;line-height:20px;">
                We sent a payout for
                <strong>{{ $business->name ?? 'business #'.$business->id }}</strong>
                and the provider accepted it without ever telling us whether it reached the bank. We asked
                {{ $checks }} {{ \Illuminate\Support\Str::plural('time', $checks) }}@if($openFor) over the {{ $openFor }}@endif
                and it has not said.
              </p>
              <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 14px;">
                <p style="margin:0 0 6px;color:#92400e;font-size:14px;line-height:20px;font-weight:600;">
                  The money is out of the wallet and we cannot confirm where it went.
                </p>
                <p style="margin:0;color:#78350f;font-size:14px;line-height:20px;">
                  <strong>Do not resend this transfer yet.</strong> Check the provider's own dashboard for the reference
                  below first — if it landed, sending it again pays the business twice, and a completed transfer cannot
                  be called back.
                </p>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 20px;">
              <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#0f172a;">
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Business</td>
                    <td style="padding:6px 0;">{{ $business->name ?? '—' }}@if(!empty($business->business_code)) <span style="color:#64748b;">({{ $business->business_code }})</span>@endif</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Amount</td>
                    <td style="padding:6px 0;font-weight:600;">{{ $currency }} {{ $amount }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Reference</td>
                    <td style="padding:6px 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;">{{ $payout->reference }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Provider</td>
                    <td style="padding:6px 0;">{{ $provider }}@if(!empty($payout->provider_payout_id)) <span style="color:#64748b;">— {{ $payout->provider_payout_id }}</span>@endif</td>
                  </tr>
                  {{--
                      The provider's own word, quoted rather than mapped onto ours. A
                      status we have not seen before is more useful here as itself: the
                      operator is about to look at the provider's dashboard, and the
                      string they will read there is this one. When there is none, say
                      so — an empty cell reads as a field the mail forgot to fill.
                  --}}
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;vertical-align:top;">Provider's last word</td>
                    <td style="padding:6px 0;">
                      @if($providerStatus)
                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;">{{ $providerStatus }}</span>
                      @else
                        <span style="color:#64748b;">None — the provider has never confirmed a status for this transfer.</span>
                      @endif
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Times we have asked</td>
                    <td style="padding:6px 0;">{{ $checks }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:190px;color:#64748b;">Sent</td>
                    <td style="padding:6px 0;">{{ $payout->sent_at?->toDayDateTimeString() ?? 'Not recorded' }}@if($openFor) <span style="color:#64748b;">— {{ $openFor }} ago</span>@endif</td>
                  </tr>
                  {{--
                      Ours, not the provider's: the send threw and this is what it threw,
                      which is the one case where the provider was never asked anything
                      and "no status" needs a reason. Capped when it was written to the
                      row, because a client library's exception can be very long.
                  --}}
                  @if(!empty($payout->failure_reason))
                    <tr>
                      <td style="padding:6px 0;width:190px;color:#64748b;vertical-align:top;">Why we have no answer</td>
                      <td style="padding:6px 0;color:#334155;">{{ $payout->failure_reason }}</td>
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
                The queue has a <strong>Check now</strong> beside every transfer still in flight, which asks the provider
                again on the spot. Confirm what happened at the provider before anything is resent.
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
