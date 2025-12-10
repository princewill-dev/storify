@php($services = ($services ?? collect()))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>404 – Page Not Found | {{ $store->name ?? config('app.name', 'Store') }}</title>
    <style>
        :root { --bg:#0b0c0e; --panel:#14161a; --muted:#a7aab2; --text:#e7e9ee; --accent:#d1d5db; --border:#24262c; }
        *{box-sizing:border-box} html,body{height:100%}
        body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Noto Sans,Ubuntu,Cantarell,Helvetica Neue,Arial; color:var(--text);
            background:radial-gradient(1200px 900px at 80% -10%, #1b1e24 0%, transparent 50%),radial-gradient(1000px 800px at -10% 110%, #15181d 0%, transparent 50%),var(--bg);
            display:grid;place-items:center}
        .wrap{width:100%;max-width:1040px;padding:48px 20px}
        .card{border:1px solid var(--border);background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,0));backdrop-filter:blur(4px);border-radius:16px;padding:40px;box-shadow:0 10px 30px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.02)}
        .brand{display:flex;align-items:center;gap:12px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;font-size:12px}
        .brand-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);opacity:.35}
        h1{margin:14px 0 10px 0;font-size:clamp(28px,5vw,44px);line-height:1.12;letter-spacing:-.02em}
        .lede{color:var(--muted);font-size:16px;max-width:60ch}
        .grid{margin-top:28px;display:grid;gap:20px;grid-template-columns:1fr}
        .divider{height:1px;background:linear-gradient(90deg,transparent,var(--border),transparent);margin:28px 0}
        .btn{appearance:none;border:1px solid var(--border);background:var(--panel);color:var(--text);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:600;transition:transform .12s ease,border-color .12s ease,background .12s ease;display:inline-block}
        .btn:hover{transform:translateY(-1px);border-color:#2a2d34}
        .btn.secondary{background:transparent;color:var(--muted)}
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
            <span>{{ strtoupper($store->name ?? config('app.name', 'Zimozi Swift')) }}</span>
            <span class="brand-dot"></span>
            <span>Not Found</span>
        </div>

        <h1>404 — Page not found</h1>
        <p class="lede">The page you’re looking for doesn’t exist or may have moved. Try the options below to continue exploring.</p>

        <div class="grid">
            <div>
                <div class="divider"></div>
                <div class="cta" style="display:flex;gap:12px;flex-wrap:wrap;">
                    <a href="{{ ($store?->slug ?? false) ? route('home.store.products.index', ['store_slug' => $store->slug]) : route('home.index') }}" class="btn">Back to Store</a>
                    <a href="mailto:{{ config('mail.from.address') }}" class="btn secondary">Contact Us</a>
                </div>
                <footer>© {{ date('Y') }} {{ $store->name ?? config('app.name', 'Zimozi Swift') }}. All rights reserved.</footer>
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
