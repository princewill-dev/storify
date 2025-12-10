<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>International Supply – Coming Soon | {{ $store->name ?? 'Store' }}</title>
    <style>
        :root {
            --bg: #0b0c0e;
            --panel: #14161a;
            --muted: #a7aab2;
            --text: #e7e9ee;
            --accent: #d1d5db;
            --border: #24262c;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Noto Sans, Ubuntu, Cantarell, Helvetica Neue, Arial, "Apple Color Emoji", "Segoe UI Emoji";
            color: var(--text);
            background: radial-gradient(1200px 900px at 80% -10%, #1b1e24 0%, transparent 50%),
                        radial-gradient(1000px 800px at -10% 110%, #15181d 0%, transparent 50%),
                        var(--bg);
            display: grid;
            place-items: center;
        }
        .wrap {
            width: 100%;
            max-width: 1040px;
            padding: 48px 20px;
        }
        .card {
            border: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.0));
            backdrop-filter: blur(4px);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.02);
        }
        .brand {
            display: flex; align-items: center; gap: 12px;
            color: var(--muted);
            letter-spacing: .08em; text-transform: uppercase;
            font-size: 12px;
        }
        .brand-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); opacity: .35; }
        h1 {
            margin: 14px 0 10px 0;
            font-size: clamp(28px, 5vw, 44px);
            line-height: 1.12;
            letter-spacing: -0.02em;
        }
        .lede { color: var(--muted); font-size: 16px; max-width: 60ch; }
        .grid { margin-top: 28px; display: grid; gap: 20px; grid-template-columns: 1fr; }
        .pill { display:inline-flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--border); border-radius:999px; color:var(--muted); font-size:12px; }
        .divider { height: 1px; background: linear-gradient(90deg, transparent, var(--border), transparent); margin: 28px 0; }
        .cta {
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        .btn {
            appearance: none; border: 1px solid var(--border);
            background: var(--panel); color: var(--text);
            padding: 10px 16px; border-radius: 10px; text-decoration: none; font-weight: 600;
            transition: transform .12s ease, border-color .12s ease, background .12s ease;
        }
        .btn:hover { transform: translateY(-1px); border-color: #2a2d34; }
        .btn.secondary { background: transparent; color: var(--muted); }
        .badges { display:flex; gap:10px; flex-wrap: wrap; }
        .badge { font-size: 11px; color: var(--muted); border: 1px dashed var(--border); padding: 6px 10px; border-radius: 999px; }
        footer { margin-top: 28px; color: var(--muted); font-size: 12px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        @media (min-width: 720px) {
            .grid { grid-template-columns: 1.2fr .8fr; align-items: start; }
        }
    </style>
    </head>
<body>
    <main class="wrap">
        <section class="card">
            <div class="brand">
                <span class="brand-dot"></span>
                <span>{{ strtoupper($store->name ?? 'Zimozi Swift') }}</span>
                <span class="brand-dot"></span>
                <span>International Supply</span>
            </div>
            <h1>Global sourcing, simplified.<br/>International Supply is coming soon.</h1>
            <p class="lede">We’re crafting a dedicated cross‑border sourcing experience to help {{ $store->name ?? 'our store' }} customers procure quality products from around the world, reliably and at scale. Stay tuned.</p>

            <div class="grid">
                <div>
                    <div class="badges">
                        <span class="badge">Neutral palette</span>
                        <span class="badge">Secure logistics</span>
                        <span class="badge">Vendor vetting</span>
                    </div>
                    <div class="divider"></div>
                    <div class="cta">
                        <a href="{{ route('home.store.products.index', ['store_slug' => $store->slug]) }}" class="btn">Back to Store</a>
                        <a href="mailto:{{ config('mail.from.address') }}" class="btn secondary">Contact Us</a>
                    </div>
                    <footer>
                        <span>© {{ date('Y') }} {{ $store->name ?? 'Zimozi Swift' }}. All rights reserved.</span>
                    </footer>
                </div>
                <div>
                    <div class="pill"><span class="mono">ETA</span> <strong>Q1–Q2</strong></div>
                    <div style="margin-top:12px; border:1px solid var(--border); border-radius:12px; padding:14px; background: rgba(255,255,255,0.02)">
                        <div style="height:180px; border-radius:8px; background: linear-gradient(135deg, #1b1f25 0%, #0f1216 100%);
                                    box-shadow: inset 0 0 0 1px #21242a; position: relative; overflow:hidden;">
                            <div style="position:absolute; inset:0; opacity:.06; filter: contrast(110%) brightness(110%); background:
                                radial-gradient(600px 200px at 30% 10%, #fff 0%, transparent 70%),
                                radial-gradient(400px 160px at 70% 120%, #fff 0%, transparent 70%);
                            "></div>
                            <div style="position:absolute; bottom:14px; left:14px; right:14px; display:flex; gap:8px;">
                                <div style="flex:1; height:8px; background:#2a2e35; border-radius:999px"></div>
                                <div style="width:18%; height:8px; background:#2a2e35; border-radius:999px"></div>
                            </div>
                        </div>
                        <p class="lede" style="margin:12px 0 0 0">A dedicated channel for international procurement with vetted suppliers, escrowed payments, and transparent delivery timelines.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
