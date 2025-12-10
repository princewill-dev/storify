@php
  $contactEmail = $company->email ?? null;
  $contactHref = $contactEmail ? ('mailto:' . $contactEmail) : 'mailto:support@example.com';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>We are setting things up</title>
  <style>
    :root{ --bg:#ffffff; --fg:#0f0f0f; --muted:#6b7280; --line:#e5e7eb; }
    @media (prefers-color-scheme: dark){ :root{ --bg:#0b0b0b; --fg:#f5f5f5; --muted:#9ca3af; --line:#1f2937; } }
    html,body{ height:100%; margin:0; background:var(--bg); color:var(--fg); font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica Neue, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
    .wrap{ min-height:100%; display:flex; align-items:center; justify-content:center; padding:40px 16px; }
    .card{ width:100%; max-width:720px; border:1px solid var(--line); border-radius:12px; padding:32px; background:var(--bg); box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .title{ font-size:28px; line-height:1.2; margin:0 0 8px; font-weight:700; letter-spacing:-0.01em; }
    .sub{ margin:0 0 20px; color:var(--muted); font-size:15px; }
    .hr{ height:1px; background:var(--line); border:0; margin:24px 0; }
    .actions{ display:flex; gap:12px; flex-wrap:wrap; }
    .btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border-radius:10px; text-decoration:none; font-weight:600; border:1px solid var(--line); color:var(--fg); background:#f9fafb; }
    .btn:hover{ background:#f3f4f6; }
    @media (prefers-color-scheme: dark){ .btn{ background:#111827; } .btn:hover{ background:#0f172a; } }
    .muted{ color:var(--muted); font-size:14px; }
  </style>
  <meta name="robots" content="noindex">
  <meta name="turbolinks-cache-control" content="no-cache">
</head>
<body>
  <div class="wrap">
    <div class="card" role="status" aria-live="polite">
      <h1 class="title">We’re setting things up</h1>
      <p class="sub">Our platform configuration is in progress. Please check back a little later.</p>
      <div class="hr"></div>
      <div class="actions">
        <!-- <a class="btn" href="/">Go to homepage</a> -->
        <a class="btn" href="{{ $contactHref }}">Contact support{{ $contactEmail ? ' (' . $contactEmail . ')' : '' }}</a>
      </div>
      <p class="muted" style="margin-top:16px;">Thank you for your patience.</p>
    </div>
  </div>
</body>
</html>
