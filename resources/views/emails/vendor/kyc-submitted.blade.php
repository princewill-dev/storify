<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KYC submission received</title>
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
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company->name ?? 'Vendor Team' }}</div>
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
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">Hi {{ $user->name }}, thanks for submitting your KYC details. Our compliance team will review the information and get back to you shortly.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 20px;">
              <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#0f172a;">
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Submitted on</td>
                    <td style="padding:6px 0;">{{ optional($application->submitted_at)->format('j M Y, g:ia') ?? now()->format('j M Y, g:ia') }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Document type</td>
                    <td style="padding:6px 0;">{{ $application->documentType?->name ?? '—' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Document ID</td>
                    <td style="padding:6px 0;">{{ $application->kyc_document_id }}</td>
                  </tr>
                </table>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">Next, we’ll confirm your details and send you an update. In the meantime, you can finish preparing your store so you’re ready to launch.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              <a href="{{ route('management.stores.create') }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:14px;">Continue store setup</a>
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
