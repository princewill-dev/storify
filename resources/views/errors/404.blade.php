<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>404 — Page not found | {{ config('app.name', 'Storify') }}</title>
    <style>
        :root { --bg:#0b0c0e; --panel:#14161a; --muted:#a7aab2; --text:#e7e9ee; --border:#24262c; }
        *{box-sizing:border-box} html,body{height:100%}
        body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            color:var(--text);background:var(--bg);display:grid;place-items:center}
        .card{width:100%;max-width:640px;margin:48px 20px;border:1px solid var(--border);background:var(--panel);
            border-radius:16px;padding:40px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
        h1{margin:0 0 10px;font-size:clamp(26px,5vw,40px);line-height:1.12;letter-spacing:-.02em}
        p{color:var(--muted);font-size:16px;line-height:1.6;margin:0}
        .btn{display:inline-block;margin-top:24px;border:1px solid var(--border);background:transparent;color:var(--text);
            padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:600}
        footer{margin-top:28px;color:var(--muted);font-size:12px}
    </style>
</head>
<body>
    <main class="card">
        <h1>404 — Page not found</h1>
        <p>
            The page you are looking for does not exist or may have moved.
            If you followed a link from an email, it may have expired.
        </p>
        <a href="mailto:{{ config('mail.from.address') }}" class="btn">Contact support</a>
        <footer>&copy; {{ date('Y') }} {{ config('app.name', 'Storify') }}. All rights reserved.</footer>
    </main>
</body>
</html>
