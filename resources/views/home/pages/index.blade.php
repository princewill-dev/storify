@extends('home.layout')
@section('title', 'E-Commerce Platform for Nigerian Businesses')

@section('content')

{{-- ═══ HERO ═══ --}}
<section class="sec" style="padding-top:120px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h1 class="h1 mb-3">Your business,<br>online and in control</h1>
                <p class="body-lg mb-4 mx-auto" style="max-width:580px;">
                    A complete e-commerce platform for Nigerian businesses. Run your storefront, manage inventory across warehouses, accept payments, and grow&nbsp;&mdash;&nbsp;all from one place.
                </p>
                <div class="d-flex justify-content-center gap-3 flex-wrap mb-3">
                    <a href="{{ route('management.auth.register') }}" class="btn-dark">Create Your Free Store</a>
                    <a href="{{ route('home.stores') }}" class="btn-outline">Browse Stores</a>
                </div>
                <p class="small muted">No credit card required. Free to start.</p>
            </div>
        </div>
    </div>
</section>

{{-- ═══ STATS ═══ --}}
<section class="sec-sm" style="border-top:1px solid #eee;border-bottom:1px solid #eee;">
    <div class="container">
        <div class="row text-center g-4">
            <div class="col-6 col-md-3"><div class="stat-num">{{ number_format($storeCount ?? 0) }}+</div><div class="stat-label">Active Stores</div></div>
            <div class="col-6 col-md-3"><div class="stat-num">{{ number_format($productCount ?? 0) }}+</div><div class="stat-label">Products Managed</div></div>
            <div class="col-6 col-md-3"><div class="stat-num">24/7</div><div class="stat-label">Support</div></div>
            <div class="col-6 col-md-3"><div class="stat-num">NG</div><div class="stat-label">Built for Nigeria</div></div>
        </div>
    </div>
</section>

{{-- ═══ FEATURES ═══ --}}
<section id="features" class="sec sec-light">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <h2 class="h2 mb-3">Everything you need to sell</h2>
                <p class="body-lg mx-auto">One platform. Every tool your business needs&nbsp;&mdash;&nbsp;from storefront to warehouse.</p>
            </div>
        </div>
        <div class="row">
            @php
            $features = [
                ['Online Storefront', 'Your own branded store with a custom subdomain. Looks professional, works everywhere. No coding required.'],
                ['Inventory Management', 'Track stock across multiple warehouses in real time. Know exactly what you have, where it is, and when to restock.'],
                ['Point of Sale', 'Sell in-store and online from the same inventory. Everything stays in sync automatically.'],
                ['Payment Processing', 'Accept payments through Paystack, bank transfer, and more. Fast settlement, full transparency.'],
                ['Team & Permissions', 'Invite your staff, assign roles, and control exactly what each person can access and do.'],
                ['Real-Time Insights', 'Dashboard shows sales, stock levels, and customer trends. Make decisions based on data, not guesswork.'],
            ];
            @endphp
            @foreach($features as $f)
            <div class="col-md-6">
                <div class="feat-item">
                    <span class="feat-marker">[*]</span>
                    <div class="feat-body"><strong>{{ $f[0] }}</strong> &mdash; {{ $f[1] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ PRODUCT PREVIEW ═══ --}}
<section class="sec">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="small muted text-uppercase" style="letter-spacing:.1em;font-weight:600;">Designed for clarity</span>
                <h2 class="h2 mt-2 mb-3">A dashboard that makes sense</h2>
                <p class="body-lg mb-4">See your sales, track stock levels, and monitor orders at a glance. No training required&nbsp;&mdash;&nbsp;everything is exactly where you expect it to be.</p>
                <div class="row g-3">
                    <div class="col-6"><strong style="font-size:20px;">Real-time</strong><br><span class="body-md">Sales and order updates</span></div>
                    <div class="col-6"><strong style="font-size:20px;">Multi-store</strong><br><span class="body-md">Manage all locations from one place</span></div>
                    <div class="col-6"><strong style="font-size:20px;">Role-based</strong><br><span class="body-md">Each staff member sees what they need</span></div>
                    <div class="col-6"><strong style="font-size:20px;">Mobile-ready</strong><br><span class="body-md">Check your business from anywhere</span></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div style="background:#f5f5f5;border-radius:12px;border:1px solid #eee;padding:40px;text-align:center;">
                    <div style="max-width:400px;margin:0 auto;">
                        <div style="background:#fff;border-radius:8px;border:1px solid #e0e0e0;padding:16px;margin-bottom:12px;text-align:left;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <div><div style="width:80px;height:8px;background:#e0e0e0;border-radius:4px;margin-bottom:6px;"></div><div style="width:120px;height:14px;background:#f0f0f0;border-radius:4px;"></div></div>
                                <div style="width:40px;height:40px;background:#1a1a1a;border-radius:8px;"></div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;">
                                <div style="background:#f9f9f9;border-radius:6px;padding:10px;text-align:center;"><div style="font-size:18px;font-weight:700;">&#8358;2.4M</div><div style="font-size:10px;color:#999;">Revenue</div></div>
                                <div style="background:#f9f9f9;border-radius:6px;padding:10px;text-align:center;"><div style="font-size:18px;font-weight:700;">143</div><div style="font-size:10px;color:#999;">Orders</div></div>
                                <div style="background:#f9f9f9;border-radius:6px;padding:10px;text-align:center;"><div style="font-size:18px;font-weight:700;">1.2K</div><div style="font-size:10px;color:#999;">Products</div></div>
                            </div>
                            <div style="height:6px;background:#eee;border-radius:3px;margin-bottom:6px;width:100%;"></div>
                            <div style="height:6px;background:#eee;border-radius:3px;margin-bottom:6px;width:70%;"></div>
                            <div style="height:6px;background:#eee;border-radius:3px;width:85%;"></div>
                        </div>
                    </div>
                    <p class="small muted mt-3 mb-0">Dashboard preview &mdash; real merchant data stays private</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══ HOW IT WORKS ═══ --}}
<section id="how-it-works" class="sec sec-light">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <h2 class="h2 mb-3">Start selling in minutes</h2>
                <p class="body-lg mx-auto">No technical skills needed. If you can fill out a form, you can run your store.</p>
            </div>
        </div>
        <div class="row g-4 justify-content-center text-center">
            @php $steps = [['01','Create your account','Sign up in seconds. No credit card, no commitment.'],['02','Set up your store','Add products, choose your subdomain, configure payments and delivery.'],['03','Start selling','Share your store link and accept orders immediately.']]; @endphp
            @foreach($steps as $s)
            <div class="col-md-4"><div class="step-num">{{ $s[0] }}</div><h3 class="h3 mb-2">{{ $s[1] }}</h3><p class="body-md mb-0">{{ $s[2] }}</p></div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ INTEGRATIONS ═══ --}}
<section class="sec">
    <div class="container text-center">
        <div class="row justify-content-center mb-5"><div class="col-lg-7">
            <h2 class="h2 mb-3">Works with the tools you rely on</h2>
            <p class="body-lg mx-auto">Built to integrate with the payment systems, delivery services, and business tools Nigerian merchants use every day.</p>
        </div></div>
        <div class="row justify-content-center g-4">
            @php
            $integrations = [
                ['Paystack', 'Accept card payments, transfers, and USSD. The standard for Nigerian online payments.'],
                ['Bank Transfer', 'Automatic bank transfer verification. Your customers pay the way they are comfortable with.'],
                ['Delivery Networks', 'Set up delivery routes and intervals. Manage shipping across cities and neighborhoods.'],
                ['Multi-currency', 'Sell in Naira with support for multiple currencies. Your store, your pricing.'],
            ];
            @endphp
            @foreach($integrations as $i)
            <div class="col-sm-6 col-lg-3 text-start"><div class="feat-item" style="border-bottom:none;"><span class="feat-marker" style="font-size:20px;">+</span><div class="feat-body"><strong>{{ $i[0] }}</strong><br><span style="color:#888;">{{ $i[1] }}</span></div></div></div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ WHY STORIFY ═══ --}}
<section class="sec sec-light">
    <div class="container">
        <div class="row justify-content-center text-center mb-5"><div class="col-lg-7"><h2 class="h2 mb-3">Built for Nigerian businesses</h2></div></div>
        <div class="row">
            @php $whys = [['No technical skills needed','If you can fill out a form, you can run your store. We handle the complexity so you can focus on your business.'],['Everything in one place','Storefront, inventory, POS, payments, team management&nbsp;&mdash;&nbsp;all included. No patching together multiple tools.'],['Scales with you','Start with one store. Add warehouses, staff, and more stores as your business grows.'],['Local by design','Paystack integration, bank transfers, Nigerian business logic&nbsp;&mdash;&nbsp;built in from day one.']]; @endphp
            @foreach($whys as $w)
            <div class="col-md-6"><div class="feat-item" style="border-bottom:none;"><span class="feat-marker" style="font-size:20px;">&mdash;</span><div class="feat-body"><strong>{{ $w[0] }}</strong><br><span style="color:#888;">{{ $w[1] }}</span></div></div></div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ STORES ═══ --}}
@if(isset($stores) && $stores->isNotEmpty())
<section class="sec">
    <div class="container">
        <div class="row justify-content-center text-center mb-5"><div class="col-lg-7"><h2 class="h2 mb-3">Stores powered by {{ $company->name }}</h2><p class="body-lg mx-auto">Discover businesses already selling with us.</p></div></div>
        <div class="row g-3">
            @foreach($stores as $store)
            <div class="col-sm-6 col-lg-4">
                <a href="{{ $store->slug ? route('home.store.products.index', ['store_subdomain' => $store->slug]) : route('home.stores') }}" class="store-tile h-100" target="_blank" rel="noopener">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        @if($store->logo_path)<img src="{{ asset('storage/'.$store->logo_path) }}" alt="{{ $store->name }}" style="width:40px;height:40px;border-radius:8px;object-fit:cover;border:1px solid #eee;">@else<div style="width:40px;height:40px;border-radius:8px;background:#f5f5f5;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;color:#aaa;">{{ strtoupper(substr($store->name,0,2)) }}</div>@endif
                        <div><strong style="color:#1a1a1a;">{{ $store->name }}</strong><div style="font-size:.8rem;color:#999;">{{ $store->vendor?->name ?? $company->name }}</div></div>
                    </div>
                    @if($store->description)<p class="mb-0" style="font-size:.85rem;color:#888;">{{ Str::limit($store->description, 90) }}</p>@endif
                </a>
            </div>
            @endforeach
        </div>
        <div class="text-center mt-5"><a href="{{ route('home.stores') }}" class="btn-outline">View all stores &rarr;</a></div>
    </div>
</section>
@endif

{{-- ═══ TESTIMONIALS ═══ --}}
@if(isset($testimonials) && $testimonials->isNotEmpty())
<section class="sec sec-light">
    <div class="container">
        <div class="row justify-content-center text-center mb-5"><div class="col-lg-7"><h2 class="h2 mb-3">What our merchants say</h2></div></div>
        <div class="row">
            @foreach($testimonials as $t)
            <div class="col-md-6 col-lg-4"><div class="testimonial"><q class="mb-2 d-block">{{ $t->content }}</q><strong>{{ $t->name }}</strong>@if($t->role)<br><small class="muted">{{ $t->role }}</small>@endif</div></div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ═══ CTA ═══ --}}
<section class="sec sec-dark text-center">
    <div class="container"><div class="row justify-content-center"><div class="col-lg-7">
        <h2 class="h2 mb-3" style="color:#fff;">Ready to start selling?</h2>
        <p class="body-lg mb-4 mx-auto" style="color:#aaa;">Create your free store today. No credit card required.</p>
        <a href="{{ route('management.auth.register') }}" class="btn-white-outline">Create Your Free Store</a>
    </div></div></div>
</section>

@endsection
