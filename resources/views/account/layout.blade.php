<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'My Account') — {{ config('app.name', 'Storify') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #334155;
            --muted: #94a3b8;
            --accent: #0f172a;
            --hover: #f1f5f9;
        }
        body { background: var(--bg); color: var(--text); font-size: 14px; }
        .sidebar { background: #fff; border-right: 1px solid var(--border); min-height: 100vh; }
        .sidebar .nav-link { color: var(--text); padding: 10px 16px; border-radius: 8px; margin-bottom: 2px; font-size: 13px; font-weight: 500; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: var(--hover); color: var(--accent); }
        .sidebar .nav-link i { width: 20px; opacity: .6; }
        .sidebar .nav-link.active i { opacity: 1; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 1px 2px rgba(0,0,0,.03); }
        .stat-card { text-align: center; padding: 20px 16px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: var(--accent); }
        .stat-card .stat-label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-top: 4px; }
        .stat-card .stat-icon { font-size: 20px; color: var(--muted); margin-bottom: 8px; }
        .topbar { background: #fff; border-bottom: 1px solid var(--border); padding: 12px 20px; }
        .btn-primary { background: var(--accent); border-color: var(--accent); }
        .btn-primary:hover { background: #1e293b; border-color: #1e293b; }
        .btn-outline { border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--hover); }
        .table { font-size: 13px; }
        .table th { color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; font-size: 11px; }
        .badge-status { padding: 4px 10px; border-radius: 50px; font-size: 11px; font-weight: 600; }
        .mobile-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid var(--border); display: flex; z-index: 1000; }
        .mobile-nav a { flex: 1; text-align: center; padding: 10px 4px; color: var(--muted); font-size: 10px; text-decoration: none; }
        .mobile-nav a i { display: block; font-size: 18px; margin-bottom: 2px; }
        .mobile-nav a.active { color: var(--accent); }
        @media (min-width: 768px) { .mobile-nav { display: none; } }
        @media (max-width: 767px) { .sidebar { display: none; } .topbar-title { font-size: 15px; } }
    </style>
    @stack('styles')
</head>
<body class="h-full">
<div class="d-flex h-full">
    {{-- Sidebar --}}
    <div class="sidebar d-none d-md-flex flex-column" style="width:240px;flex-shrink:0;">
        <div class="px-4 py-4 border-bottom">
            <a href="{{ url('/') }}" class="text-decoration-none fw-bold text-dark fs-5">{{ config('app.name', 'Storify') }}</a>
        </div>
        <div class="px-3 py-4 border-bottom">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white fw-bold" style="width:40px;height:40px;font-size:16px;">
                    {{ strtoupper(substr(auth('customer')->user()?->first_name ?? 'U', 0, 1)) }}
                </div>
                <div>
                    <p class="mb-0 fw-semibold small">{{ auth('customer')->user()?->full_name ?? 'User' }}</p>
                    <p class="mb-0 text-muted" style="font-size:11px;">{{ auth('customer')->user()?->email ?? '' }}</p>
                </div>
            </div>
        </div>
        <nav class="flex-grow-1 px-3 py-3 d-flex flex-column">
            <a href="{{ route('account.dashboard') }}" class="nav-link {{ request()->routeIs('account.dashboard') ? 'active' : '' }}">
                <i class="fa-solid fa-grid-2 me-2"></i> Dashboard
            </a>
            <a href="{{ route('account.info') }}" class="nav-link {{ request()->routeIs('account.info') ? 'active' : '' }}">
                <i class="fa-solid fa-user me-2"></i> Profile & Addresses
            </a>
            <a href="{{ route('account.orders') }}" class="nav-link {{ request()->routeIs('account.orders') || request()->routeIs('account.order.show') ? 'active' : '' }}">
                <i class="fa-solid fa-bag-shopping me-2"></i> Orders
            </a>
            <a href="{{ route('account.transactions') }}" class="nav-link {{ request()->routeIs('account.transactions') || request()->routeIs('account.transaction.show') ? 'active' : '' }}">
                <i class="fa-solid fa-credit-card me-2"></i> Transactions
            </a>
            <div class="mt-auto pt-3 border-top">
                <form method="POST" action="{{ route('account.logout') }}">
                    @csrf
                    <button type="submit" class="nav-link border-0 bg-transparent w-100 text-start text-danger">
                        <i class="fa-solid fa-right-from-bracket me-2"></i> Logout
                    </button>
                </form>
            </div>
        </nav>
    </div>

    {{-- Main --}}
    <div class="flex-grow-1 d-flex flex-column min-w-0">
        <div class="topbar d-flex align-items-center justify-content-between">
            <span class="fw-semibold topbar-title">@yield('subtitle', 'My Account')</span>
            <a href="{{ url('/') }}" class="text-decoration-none text-muted small">← Back to Store</a>
        </div>
        <div class="flex-grow-1 p-3 p-md-4" style="overflow-y:auto;">
            @yield('content')
        </div>
    </div>
</div>

{{-- Mobile Bottom Nav --}}
<div class="mobile-nav d-md-none">
    <a href="{{ route('account.dashboard') }}" class="{{ request()->routeIs('account.dashboard') ? 'active' : '' }}">
        <i class="fa-solid fa-grid-2"></i> Home
    </a>
    <a href="{{ route('account.info') }}" class="{{ request()->routeIs('account.info') ? 'active' : '' }}">
        <i class="fa-solid fa-user"></i> Profile
    </a>
    <a href="{{ route('account.orders') }}" class="{{ request()->routeIs('account.orders', 'account.order.show') ? 'active' : '' }}">
        <i class="fa-solid fa-bag-shopping"></i> Orders
    </a>
    <a href="{{ route('account.transactions') }}" class="{{ request()->routeIs('account.transactions', 'account.transaction.show') ? 'active' : '' }}">
        <i class="fa-solid fa-credit-card"></i> Payments
    </a>
</div>

@stack('scripts')
</body>
</html>
