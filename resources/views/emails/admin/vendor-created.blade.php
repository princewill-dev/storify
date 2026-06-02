<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New Vendor Created</title>
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
                <div style="font-weight:700;font-size:18px;color:#111827;">{{ $company->name ?? 'Company' }}</div>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:8px 32px 4px;">
              <h1 style="margin:0;font-size:22px;line-height:28px;color:#111827;font-weight:700;">New Vendor Created</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 16px;">
              <p style="margin:0;color:#334155;font-size:14px;line-height:20px;">A new vendor has been added by {{ auth()->user()->name ?? 'an admin' }}.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 20px;">
              <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#0f172a;">
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Vendor Name</td>
                    <td style="padding:6px 0;">{{ $user->name }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Email</td>
                    <td style="padding:6px 0;">{{ $user->email ?? '—' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Phone</td>
                    <td style="padding:6px 0;">{{ $user->phone ?? '—' }}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;width:160px;color:#64748b;">Status</td>
                    <td style="padding:6px 0;"><span style="background:#eef2ff;color:#1e40af;padding:2px 8px;border-radius:999px;">{{ $user->status }}</span></td>
                  </tr>
                </table>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 28px;">
              @php($usersUrl = app('router')->has('admin.vendors.index') ? route('admin.vendors.index') : url('/superadmin/vendors'))
              <a href="{{ $usersUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:14px;">View vendors</a>
            </td>
          </tr>
          <tr>
            <td style="padding:12px 32px 28px;border-top:1px solid #e5e7eb;color:#64748b;font-size:12px;line-height:18px;">
              <div>{{ $company->name ?? 'Company' }} • {{ $company->email ?? '' }} • {{ $company->phone ?? '' }}</div>
              <div>{{ $company->address ?? '' }}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
