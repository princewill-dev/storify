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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="{{ asset('home/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('home/css/flaticon.css') }}" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #1a1a1a;
            background: #fff;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            margin: 0; padding: 0;
        }
        a { color: inherit; }

        /* Nav */
        .nav-wrap {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #e8e8e8;
        }
        .nav-inner {
            max-width: 1200px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between; height: 60px;
        }
        .nav-logo img { height: 28px; display: block; }
        .nav-links { display: flex; align-items: center; gap: 28px; }
        .nav-links a { font-size: 14px; font-weight: 500; color: #555; text-decoration: none; transition: color .15s; }
        .nav-links a:hover { color: #1a1a1a; }
        .nav-cta {
            font-size: 14px; font-weight: 600; color: #fff; background: #1a1a1a;
            padding: 9px 20px; border-radius: 6px; text-decoration: none; transition: background .15s;
        }
        .nav-cta:hover { background: #333; color: #fff; }
        .nav-toggle { display: none; background: none; border: none; font-size: 22px; cursor: pointer; padding: 4px; }
        .nav-mobile { display: none; flex-direction: column; gap: 12px; padding: 16px 24px; border-top: 1px solid #e8e8e8; }
        .nav-mobile a { font-size: 15px; font-weight: 500; color: #555; text-decoration: none; }
        .nav-mobile .nav-cta { display: inline-block; text-align: center; margin-top: 8px; }

        /* Sections */
        .sec { padding: 100px 0; }
        .sec-sm { padding: 60px 0; }
        .sec-light { background: #f9f9f9; }
        .sec-dark { background: #1a1a1a; color: #fff; }

        /* Typography */
        .h1 { font-size: clamp(40px, 6vw, 72px); font-weight: 800; color: #1a1a1a; line-height: 1.08; letter-spacing: -0.025em; }
        .h2 { font-size: clamp(32px, 4.5vw, 48px); font-weight: 700; color: #1a1a1a; line-height: 1.15; letter-spacing: -0.02em; }
        .h3 { font-size: 20px; font-weight: 600; color: #1a1a1a; }
        .body-lg { font-size: 18px; color: #555; line-height: 1.6; }
        .body-md { font-size: 15px; color: #666; line-height: 1.6; }
        .muted { color: #999; }
        .mono { font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace; font-size: 14px; }

        /* Buttons */
        .btn-dark {
            display: inline-block; font-weight: 600; font-size: 15px;
            padding: 13px 32px; border-radius: 7px; text-decoration: none;
            background: #1a1a1a; color: #fff; border: 2px solid #1a1a1a;
            transition: all .15s;
        }
        .btn-dark:hover { background: #333; border-color: #333; color: #fff; }
        .btn-outline {
            display: inline-block; font-weight: 600; font-size: 15px;
            padding: 13px 32px; border-radius: 7px; text-decoration: none;
            background: transparent; color: #1a1a1a; border: 2px solid #d4d4d4;
            transition: all .15s;
        }
        .btn-outline:hover { border-color: #1a1a1a; background: #1a1a1a; color: #fff; }
        .btn-white-outline {
            display: inline-block; font-weight: 600; font-size: 15px;
            padding: 13px 32px; border-radius: 7px; text-decoration: none;
            background: transparent; color: #fff; border: 2px solid rgba(255,255,255,0.4);
            transition: all .15s;
        }
        .btn-white-outline:hover { border-color: #fff; background: rgba(255,255,255,0.1); color: #fff; }

        /* Stats */
        .stat-num { font-size: clamp(32px, 5vw, 56px); font-weight: 800; color: #1a1a1a; line-height: 1; letter-spacing: -0.025em; }
        .stat-label { font-size: 14px; color: #888; margin-top: 4px; }

        /* Feature list */
        .feat-item { display: flex; gap: 14px; padding: 16px 0; border-bottom: 1px solid #eee; }
        .feat-marker { color: #ccc; font-weight: 700; font-size: 16px; flex-shrink: 0; margin-top: 2px; }
        .feat-body strong { font-weight: 600; color: #1a1a1a; }
        .feat-body { font-size: 15px; color: #666; line-height: 1.55; }

        /* Step */
        .step-num { font-size: 64px; font-weight: 800; color: #e0e0e0; line-height: 1; margin-bottom: 10px; }

        /* Store tile */
        .store-tile {
            display: block; padding: 20px; border: 1px solid #eee; border-radius: 8px;
            text-decoration: none; transition: border-color .15s;
        }
        .store-tile:hover { border-color: #1a1a1a; }

        /* Testimonial */
        .testimonial { border-left: 2px solid #e0e0e0; padding-left: 18px; margin-bottom: 20px; }
        .testimonial q { font-style: italic; color: #666; font-size: 15px; line-height: 1.55; }

        /* FAQ */
        .faq-item { border-bottom: 1px solid #eee; padding: 18px 0; }
        .faq-q { font-weight: 600; font-size: 16px; color: #1a1a1a; }
        .faq-a { color: #666; font-size: 15px; line-height: 1.55; padding-top: 6px; }

        /* Footer */
        .ftr { background: #1a1a1a; color: #999; padding: 60px 0 30px; font-size: 14px; }
        .ftr h6 { color: #fff; font-weight: 600; font-size: 14px; margin-bottom: 14px; }
        .ftr a { color: #999; text-decoration: none; }
        .ftr a:hover { color: #fff; }
        .ftr ul { list-style: none; padding: 0; margin: 0; }
        .ftr ul li { margin-bottom: 8px; }
        .ftr hr { border-color: rgba(255,255,255,0.1); margin: 30px 0 20px; }

        @media (max-width: 768px) {
            .sec { padding: 60px 0; }
            .sec-sm { padding: 40px 0; }
            .nav-links { display: none; }
            .nav-toggle { display: block; }
            .nav-mobile.open { display: flex; }
            .h1 { font-size: 36px; }
            .h2 { font-size: 28px; }
            .body-lg { font-size: 16px; }
            .step-num { font-size: 42px; }
            .stat-num { font-size: 28px; }
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
</script>
@stack('scripts')
</body>
</html>
