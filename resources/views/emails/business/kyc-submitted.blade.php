<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KYC submission received</title>
</head>
<body style="margin:0;background:#f4f6f8;color:#0f172a;">
  {{--
      Two sentences and a button, and deliberately nothing else.

      A table used to sit between them listing the submitted-on date, the
      document type and the document number. For a BVN or a NIN the last of
      those is a regulated identifier, and mail is not a channel this platform
      controls: it is stored, forwarded, indexed, and read on devices that are
      somebody else's. An acknowledgement is not worth a permanent copy of a
      person's identity number sitting in an inbox for ever.

      Nothing is lost by removing it. The reader knows all they need to — that it
      arrived and that they will hear back — and everything the review needs is
      in the console, behind a permission and an audit log.
  --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.06);">
          <tr>
            <td style="padding:28px 32px 0;text-align:center;">
              @if(!empty($company->logo))
                <img src="{{ $company->logo }}" alt="{{ $company->name }}" style="height:28px;object-fit:contain;">
              @else
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company->name ?? 'Business Team' }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 4px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">We’ve received your KYC</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">Hi {{ $user->name }}, we’ve received your KYC submission and will let you know as soon as it has been reviewed.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">In the meantime, you can keep setting up your store.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <a href="{{ \App\Support\SpaUrls::management('/settings/payment-settings') }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:14px;">Continue your setup</a>
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
