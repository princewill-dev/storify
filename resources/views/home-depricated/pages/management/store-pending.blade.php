@php($services = ($services ?? collect()))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Store Pending Activation | {{ $store->name ?? config('app.name', 'Store') }}</title>
    <style>
        :root { --bg:#0b0c0e; --panel:#14161a; --muted:#a7aab2; --text:#e7e9ee; --accent:#fbbf24; --border:#24262c; }
        *{box-sizing:border-box} html,body{height:100%}
        body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Noto Sans,Ubuntu,Cantarell,Helvetica Neue,Arial; color:var(--text);
            background:radial-gradient(1200px 900px at 80% -10%, #1b1e24 0%, transparent 50%),radial-gradient(1000px 800px at -10% 110%, #15181d 0%, transparent 50%),var(--bg);
            display:grid;place-items:center}
        .wrap{width:100%;max-width:1040px;padding:48px 20px}
        .card{border:1px solid var(--border);background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,0));backdrop-filter:blur(4px);border-radius:16px;padding:40px;box-shadow:0 10px 30px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.02)}
        .brand{display:flex;align-items:center;gap:12px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;font-size:12px}
        .brand-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);opacity:.6;animation:pulse 2s ease-in-out infinite}
        @keyframes pulse { 0%, 100% { opacity: .4; } 50% { opacity: 1; } }
        h1{margin:14px 0 10px 0;font-size:clamp(28px,5vw,44px);line-height:1.12;letter-spacing:-.02em}
        .lede{color:var(--muted);font-size:16px;max-width:60ch;line-height:1.6}
        .grid{margin-top:28px;display:grid;gap:20px;grid-template-columns:1fr}
        .divider{height:1px;background:linear-gradient(90deg,transparent,var(--border),transparent);margin:28px 0}
        .btn{appearance:none;border:1px solid var(--border);background:var(--panel);color:var(--text);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:600;transition:transform .12s ease,border-color .12s ease,background .12s ease;display:inline-block}
        .btn:hover{transform:translateY(-1px);border-color:#2a2d34}
        .btn.secondary{background:transparent;color:var(--muted)}
        .btn.primary{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#000;border-color:#fbbf24}
        .btn.primary:hover{border-color:#fcd34d}
        .info-box{border:1px solid rgba(251,191,36,.2);background:rgba(251,191,36,.05);border-radius:12px;padding:20px;margin-top:20px}
        .info-box h3{margin:0 0 8px 0;font-size:15px;color:var(--accent)}
        .info-box p{margin:0;color:var(--muted);font-size:14px;line-height:1.5}
        .links{display:grid;gap:10px}
        .link-item{display:flex;justify-content:space-between;align-items:center;border:1px dashed var(--border);border-radius:10px;padding:10px 14px;color:var(--muted);text-decoration:none}
        .link-item:hover{border-color:#2a2d34;color:var(--text)}
        .arrow{opacity:.6}
        footer{margin-top:28px;color:var(--muted);font-size:12px}
        @media (min-width:720px){ .grid{grid-template-columns:1.2fr .8fr;align-items:start} }
    </style>
</head>
<body>
<main class="wrap">
    <section class="card">
        <div class="brand">
            <span class="brand-dot"></span>
            <span>{{ strtoupper($store->name ?? config('app.name', 'Store')) }}</span>
            <span class="brand-dot"></span>
            <span>Pending Activation</span>
        </div>

        <h1>🕒 Store Under Review</h1>
        <p class="lede">
            <strong>{{ $store->name }}</strong> is currently pending activation. Our team is reviewing the store information and will activate it shortly.
        </p>

        <div class="grid">
            <div>
                <div class="info-box">
                    <h3>👋 Are you the store owner?</h3>
                    <p>If you're the owner of this store and need immediate assistance, please contact our support team to expedite the activation process.</p>
                </div>

                <div class="info-box" style="margin-top:16px;border-color:rgba(167,170,178,.2);background:rgba(167,170,178,.05)">
                    <h3>🛍️ Just browsing?</h3>
                    <p>This store will be available soon! Please check back later or explore other stores and services while you wait.</p>
                </div>

                <div class="divider"></div>
                <div class="cta" style="display:flex;gap:12px;flex-wrap:wrap;">
                    <a href="mailto:{{ config('mail.from.address') }}" class="btn primary">Contact Support</a>
                    <a href="{{ route('home.index') }}" class="btn">Browse Other Stores</a>
                </div>
                <footer>© {{ date('Y') }} {{ config('app.name', 'Zimozi Swift') }}. All rights reserved.</footer>
            </div>
            <div>
                @if($services->isNotEmpty())
                    <div class="brand" style="margin-bottom:8px;color:var(--text);text-transform:none;letter-spacing:0;font-size:13px;">Explore our services</div>
                    <div class="links">
                        @foreach($services as $service)
                            @if(!empty($service->page_link))
                                <a class="link-item" href="{{ $service->page_link }}">
                                    <span>{{ $service->title }}</span>
                                    <span class="arrow">→</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
</main>
</body>
</html>
