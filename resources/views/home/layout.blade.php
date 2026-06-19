<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('home.components.seo', ['seo' => ['title' => $company->og_title, 'description' => $company->og_description, 'image' => $company->og_image, 'url' => $company->og_url, 'type' => $company->og_type, 'twitter_card' => 'summary_large_image']])
    <title>{{ $company->name }} | @yield('title')</title>
    <link rel="shortcut icon" href="{{ $company->favicon }}" type="image/x-icon">
    <link rel="icon" href="{{ $company->favicon }}" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('home/css/bootstrap.min.css') }}" rel="stylesheet">
    <style>
        :root {
            --accent: #a3e635;
            --accent-hover: #84cc16;
            --accent-soft: rgba(163,230,53,0.12);
            --accent-glow: rgba(163,230,53,0.2);
            --bg: #0a0a0c;
            --bg-card: #141417;
            --bg-card-hover: #1a1a1f;
            --bg-section: #0e0e11;
            --bg-elevated: #18181c;
            --text: #f4f4f5;
            --text-secondary: #a1a1aa;
            --text-muted: #71717a;
            --border: #27272a;
            --border-light: #1f1f23;
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            margin: 0; padding: 0; overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }

        /* ── Nav ── */
        .nav-wrap {
            position: sticky; top: 0; z-index: 100;
            background: rgba(10,10,12,0.8);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }
        .nav-inner {
            max-width: 1200px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between; height: 68px;
        }
        .nav-logo img { height: 28px; display: block; filter: brightness(0) invert(1); }
        .nav-links { display: flex; align-items: center; gap: 32px; }
        .nav-links a { font-size: 14px; font-weight: 500; color: var(--text-secondary); transition: color .15s; }
        .nav-links a:hover { color: #fff; }
        .nav-cta {
            font-size: 14px; font-weight: 600; color: #0a0a0c; background: var(--accent);
            padding: 10px 24px; border-radius: 8px; transition: all .2s;
        }
        .nav-cta:hover { background: var(--accent-hover); color: #0a0a0c; transform: translateY(-1px); box-shadow: 0 0 20px var(--accent-glow); }
        .nav-toggle { display: none; background: none; border: none; font-size: 22px; cursor: pointer; color: #fff; padding: 4px; }
        .nav-mobile { display: none; flex-direction: column; gap: 14px; padding: 20px 24px 24px; border-top: 1px solid var(--border); background: var(--bg); }
        .nav-mobile a { font-size: 15px; font-weight: 500; color: var(--text-secondary); }
        .nav-mobile .nav-cta { display: inline-block; text-align: center; margin-top: 8px; }

        /* ── Sections ── */
        .sec { padding: 120px 0; }
        .sec-sm { padding: 60px 0; }
        .sec-alt { background: var(--bg-section); }

        /* ── Typography ── */
        .h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(42px, 6vw, 72px); font-weight: 700; color: #fff; line-height: 1.05; letter-spacing: -0.03em; }
        .h2 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(30px, 4vw, 48px); font-weight: 700; color: #fff; line-height: 1.1; letter-spacing: -0.025em; }
        .h3 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 600; color: #fff; }
        .body-lg { font-size: 18px; color: var(--text-secondary); line-height: 1.7; }
        .body-md { font-size: 15px; color: var(--text-muted); line-height: 1.65; }
        .label { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--accent); }
        .accent { color: var(--accent); }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 15px;
            padding: 14px 28px; border-radius: 10px; border: none; cursor: pointer;
            transition: all 0.2s; font-family: inherit; text-decoration: none;
        }
        .btn-primary { background: var(--accent); color: #0a0a0c; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 0 30px var(--accent-glow); color: #0a0a0c; }
        .btn-ghost { background: transparent; color: #fff; border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--text-muted); background: rgba(255,255,255,0.04); color: #fff; }
        .btn-lg { padding: 16px 36px; font-size: 16px; border-radius: 12px; }

        /* ── Cards ── */
        .glass-card {
            background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px;
            padding: 32px; transition: all 0.3s; position: relative; overflow: hidden;
        }
        .glass-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent);
        }
        .glass-card:hover { border-color: var(--text-muted); background: var(--bg-card-hover); transform: translateY(-2px); }
        .glass-card .card-icon {
            width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 22px; margin-bottom: 20px; background: var(--accent-soft); color: var(--accent);
        }

        .pricing-card {
            background: var(--bg-card); border: 1px solid var(--border); border-radius: 20px;
            padding: 40px 32px; transition: all 0.3s; position: relative;
        }
        .pricing-card:hover { border-color: var(--text-muted); }
        .pricing-card.featured { border-color: var(--accent); box-shadow: 0 0 40px var(--accent-soft); }
        .pricing-card.featured::before {
            content: 'Popular'; position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            background: var(--accent); color: #0a0a0c; font-size: 11px; font-weight: 700; padding: 4px 16px;
            border-radius: 20px; text-transform: uppercase; letter-spacing: 0.05em;
        }

        /* ── Stats ── */
        .stat-num { font-family: 'Space Grotesk', sans-serif; font-size: clamp(36px, 5vw, 56px); font-weight: 700; color: #fff; line-height: 1; letter-spacing: -0.02em; }
        .stat-label { font-size: 14px; color: var(--text-muted); margin-top: 6px; }

        /* ── Steps ── */
        .step-num { font-family: 'Space Grotesk', sans-serif; font-size: 64px; font-weight: 700; color: var(--border); line-height: 1; margin-bottom: 16px; }

        /* ── Media placeholder ── */
        .media-block {
            border-radius: 20px; border: 1px solid var(--border); overflow: hidden; position: relative;
            display: flex; align-items: center; justify-content: center; min-height: 340px;
            background: radial-gradient(ellipse at 50% 0%, var(--accent-soft) 0%, var(--bg-card) 70%);
        }
        .media-block::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(163,230,53,0.06) 0%, transparent 60%);
        }

        /* ── Store tiles ── */
        .store-tile {
            display: block; padding: 24px; border: 1px solid var(--border); border-radius: 14px;
            transition: all 0.2s;
        }
        .store-tile:hover { border-color: var(--accent); background: var(--bg-card); transform: translateY(-1px); }

        /* ── Testimonials ── */
        .testimonial-card {
            background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 28px;
        }
        .testimonial-card q { font-style: italic; color: var(--text-secondary); font-size: 15px; line-height: 1.65; }

        /* ── FAQ ── */
        .faq-item { border-bottom: 1px solid var(--border); }
        .faq-q {
            width: 100%; text-align: left; background: none; border: none; padding: 22px 0;
            font-weight: 600; font-size: 16px; color: #fff; cursor: pointer;
            display: flex; align-items: center; justify-content: space-between; font-family: inherit;
        }
        .faq-q:hover { color: var(--accent); }
        .faq-q .faq-arrow { transition: transform 0.2s; font-size: 14px; color: var(--text-muted); }
        .faq-q.open .faq-arrow { transform: rotate(45deg); color: var(--accent); }
        .faq-a { color: var(--text-secondary); font-size: 15px; line-height: 1.65; padding-bottom: 22px; display: none; }
        .faq-a.open { display: block; }

        /* ── Footer ── */
        .ftr { background: var(--bg-section); color: var(--text-muted); padding: 80px 0 30px; font-size: 14px; border-top: 1px solid var(--border); }
        .ftr h6 { color: #fff; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 18px; }
        .ftr a { color: var(--text-muted); transition: color .15s; }
        .ftr a:hover { color: var(--accent); }
        .ftr ul { list-style: none; padding: 0; margin: 0; }
        .ftr ul li { margin-bottom: 10px; }
        .ftr hr { border-color: var(--border); margin: 40px 0 24px; }

        /* ── Glow decoration ── */
        .hero-glow {
            position: absolute; width: 600px; height: 400px; border-radius: 50%;
            background: radial-gradient(closest-side, var(--accent-glow), transparent);
            filter: blur(80px); pointer-events: none; z-index: 0;
        }

        /* ── Animations ── */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .anim { animation: fadeInUp 0.7s ease-out both; }
        .anim-d1 { animation-delay: 0.1s; }
        .anim-d2 { animation-delay: 0.2s; }
        .anim-d3 { animation-delay: 0.3s; }

        @media (max-width: 768px) {
            .sec { padding: 70px 0; }
            .nav-links { display: none; }
            .nav-toggle { display: block; }
            .nav-mobile.open { display: flex; }
            .h1 { font-size: 34px; }
            .h2 { font-size: 28px; }
            .body-lg { font-size: 16px; }
            .step-num { font-size: 42px; }
            .stat-num { font-size: 30px; }
            .media-block { min-height: 220px; }
            .glass-card, .pricing-card { padding: 24px; }
        }
    </style>
    @stack('styles')
</head>
<body>

@include('home.components.header')

@yield('content')

@include('home.components.footer')

<script>
document.getElementById('navToggle')?.addEventListener('click', function(){
    document.getElementById('navMobile').classList.toggle('open');
});
document.querySelectorAll('.faq-q').forEach(btn => {
    btn.addEventListener('click', function() {
        this.classList.toggle('open');
        this.nextElementSibling.classList.toggle('open');
    });
});
const io = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) e.target.style.animationPlayState = 'running'; });
}, { threshold: 0.1 });
document.querySelectorAll('.anim').forEach(el => { el.style.animationPlayState = 'paused'; io.observe(el); });
</script>
@stack('scripts')
</body>
</html>
