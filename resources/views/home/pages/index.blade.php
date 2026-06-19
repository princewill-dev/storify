@extends('home.layout')
@section('title', 'E-Commerce & Inventory Platform for Nigerian Businesses')

@section('content')

{{-- ═══ HERO ═══ --}}
<section class="sec" style="padding-top:140px;padding-bottom:80px;position:relative;overflow:hidden;">
    <div class="hero-glow" style="top:-200px;left:50%;transform:translateX(-50%);"></div>
    <div class="container position-relative" style="z-index:1;">
        <div class="row justify-content-center">
            <div class="col-lg-9 text-center">
                <div class="mb-4">
                    <span style="display:inline-flex;align-items:center;gap:8px;padding:6px 16px;border-radius:50px;background:var(--accent-soft);font-size:13px;font-weight:600;color:var(--accent);">
                        &#9889; Built for Nigerian businesses
                    </span>
                </div>
                <h1 class="h1 mb-4">Manage your business<br><span class="accent">sales, inventory & growth</span><br>from one place</h1>
                <p class="body-lg mb-5 mx-auto" style="max-width:580px;">
                    Run your storefront, track stock across warehouses, process POS sales, and accept payments. Everything syncs. No complexity.
                </p>
                <div class="d-flex justify-content-center gap-3 flex-wrap mb-3">
                    <a href="{{ route('management.auth.register') }}" class="btn btn-primary btn-lg">Get Started Free &rarr;</a>
                    <a href="#features" class="btn btn-ghost btn-lg">Explore Features</a>
                </div>
                <p class="body-md mt-3" style="font-size:13px;">No credit card &middot; Free to start &middot; Set up in 2 minutes</p>
            </div>
        </div>

        {{-- Hero feature cards --}}
        <div class="row g-3 mt-5 pt-3 justify-content-center">
            @php $teasers = [
                ['&#127760;', 'Online Storefront', 'Your branded store with custom domain. No code.'],
                ['&#128230;', 'Smart Inventory', 'Multi-warehouse stock tracking in real time.'],
                ['&#128179;', 'POS Terminal', 'Sell in-store. Auto-sync with online stock.'],
            ]; @endphp
            @foreach($teasers as $i => $t)
            <div class="col-md-4">
                <div class="glass-card anim anim-d{{ $i+1 }}" style="padding:24px;">
                    <div class="card-icon" style="margin-bottom:14px;">{!! $t[0] !!}</div>
                    <strong style="font-size:15px;">{{ $t[1] }}</strong>
                    <p class="body-md mb-0" style="margin-top:6px;font-size:14px;">{{ $t[2] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ STATS ═══ --}}
<section class="sec-sm" style="border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
    <div class="container">
        <div class="row text-center g-4">
            <div class="col-6 col-md-3"><div class="stat-num accent">{{ number_format($storeCount ?? 0) }}+</div><div class="stat-label">Active Stores</div></div>
            <div class="col-6 col-md-3"><div class="stat-num accent">{{ number_format($productCount ?? 0) }}+</div><div class="stat-label">Products Managed</div></div>
            <div class="col-6 col-md-3"><div class="stat-num">24/7</div><div class="stat-label">Customer Support</div></div>
            <div class="col-6 col-md-3"><div class="stat-num">100%</div><div class="stat-label">Built for Nigeria</div></div>
        </div>
    </div>
</section>

{{-- ═══ FEATURES ═══ --}}
<section id="features" class="sec">
    <div class="container">
        <div class="row justify-content-center text-center mb-5 pb-3">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Platform Features</span>
                <h2 class="h2 mb-3">Everything you need,<br>nothing you don't</h2>
                <p class="body-lg">From storefront to warehouse, POS to analytics — one platform, fully integrated.</p>
            </div>
        </div>

        {{-- Feature 1 --}}
        <div class="row align-items-center g-5 mb-5 pb-5" style="border-bottom:1px solid var(--border);">
            <div class="col-lg-6">
                <span class="label mb-2 d-block">Online Storefront</span>
                <h3 class="h2 mb-3" style="font-size:34px;">Your own store,<br>live in minutes</h3>
                <p class="body-lg mb-4">Create a branded online store with your own subdomain. Add products, configure payments, start selling — zero code required.</p>
                <div class="row g-3 mb-4">
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Custom domain</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Mobile-optimized</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Product catalog</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">SEO built-in</span></div>
                </div>
                <a href="{{ route('management.auth.register') }}" class="btn btn-primary">Create Your Store &rarr;</a>
            </div>
            <div class="col-lg-6"><div class="media-block"><span style="font-size:80px;position:relative;z-index:1;">&#127760;</span></div></div>
        </div>

        {{-- Feature 2 --}}
        <div class="row align-items-center g-5 mb-5 pb-5" style="border-bottom:1px solid var(--border);">
            <div class="col-lg-6 order-lg-2">
                <span class="label mb-2 d-block">Inventory Management</span>
                <h3 class="h2 mb-3" style="font-size:34px;">Real-time stock<br>across every location</h3>
                <p class="body-lg mb-4">Track stock across multiple warehouses and stores. Automatic sync, low-stock alerts, and a full audit trail of every movement.</p>
                <div class="row g-3 mb-4">
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Multi-warehouse</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Auto-sync</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Low stock alerts</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Movement logs</span></div>
                </div>
                <a href="{{ route('management.auth.register') }}" class="btn btn-primary">Start Managing Inventory &rarr;</a>
            </div>
            <div class="col-lg-6 order-lg-1"><div class="media-block"><span style="font-size:80px;position:relative;z-index:1;">&#128230;</span></div></div>
        </div>

        {{-- Feature 3 --}}
        <div class="row align-items-center g-5 mb-5 pb-5" style="border-bottom:1px solid var(--border);">
            <div class="col-lg-6">
                <span class="label mb-2 d-block">Point of Sale</span>
                <h3 class="h2 mb-3" style="font-size:34px;">Sell in-store. Online.<br>Same inventory.</h3>
                <p class="body-lg mb-4">Process walk-in sales with our POS terminal. Cash, card, or transfer — inventory stays perfectly synced across all channels.</p>
                <div class="row g-3 mb-4">
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Multi-payment</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Session mgmt</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Inventory sync</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Digital receipts</span></div>
                </div>
                <a href="{{ route('management.auth.register') }}" class="btn btn-primary">Try POS Terminal &rarr;</a>
            </div>
            <div class="col-lg-6"><div class="media-block"><span style="font-size:80px;position:relative;z-index:1;">&#128179;</span></div></div>
        </div>

        {{-- Feature 4 --}}
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <span class="label mb-2 d-block">Payments</span>
                <h3 class="h2 mb-3" style="font-size:34px;">Accept payments<br>your way</h3>
                <p class="body-lg mb-4">Paystack cards, bank transfers, cash — your customers pay how they want. Instant confirmation, automatic reconciliation.</p>
                <div class="row g-3 mb-4">
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Paystack cards</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Bank transfers</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Cash & POS</span></div>
                    <div class="col-6"><span class="accent" style="font-weight:700;">&#10003;</span> <span class="body-md">Auto receipts</span></div>
                </div>
                <a href="{{ route('management.auth.register') }}" class="btn btn-primary">Get Started &rarr;</a>
            </div>
            <div class="col-lg-6 order-lg-1"><div class="media-block"><span style="font-size:80px;position:relative;z-index:1;">&#128176;</span></div></div>
        </div>
    </div>
</section>

{{-- ═══ HOW IT WORKS ═══ --}}
<section id="how-it-works" class="sec sec-alt">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Get started fast</span>
                <h2 class="h2 mb-3">Three steps to launch</h2>
                <p class="body-lg">No technical skills needed. Set up your store in minutes, not days.</p>
            </div>
        </div>
        <div class="row g-4 justify-content-center">
            @php $steps = [
                ['01','Create your account','Sign up in seconds. Just your email — no credit card, no commitments.'],
                ['02','Set up your store','Add products, connect payments, set delivery routes. Everything guided.'],
                ['03','Start selling','Share your link. Process POS sales. Accept orders immediately.']
            ]; @endphp
            @foreach($steps as $s)
            <div class="col-md-4 text-center">
                <div class="step-num">{{ $s[0] }}</div>
                <h3 class="h3 mb-2">{{ $s[1] }}</h3>
                <p class="body-md mb-0">{{ $s[2] }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ PRICING ═══ --}}
<section id="pricing" class="sec">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Transparent pricing</span>
                <h2 class="h2 mb-3">Plans that scale with you</h2>
                <p class="body-lg">Start free. Upgrade when you grow. No surprises.</p>
            </div>
        </div>
        <div class="row g-4 justify-content-center">
            @php $plans = [
                ['Starter', '0', 'Free forever', ['1 Store','100 products','Online storefront','Basic analytics','Email support'], false],
                ['Pro', '15,000', '/month', ['3 Stores','Unlimited products','POS terminal','Inventory tracking','Priority support','Team roles'], true],
                ['Business', '45,000', '/month', ['10 Stores','Unlimited products','Multi-warehouse','Advanced analytics','API access','Dedicated support'], false],
            ]; @endphp
            @foreach($plans as $plan)
            <div class="col-lg-4 col-md-6">
                <div class="pricing-card {{ $plan[4] ? 'featured' : '' }} text-center h-100 d-flex flex-column">
                    <h3 class="h3 mb-1">{{ $plan[0] }}</h3>
                    <div class="mb-4">
                        <span style="font-size:48px;font-weight:700;font-family:'Space Grotesk',sans-serif;color:#fff;">&#8358;{{ $plan[1] }}</span>
                        <span class="body-md d-block mt-1">{{ $plan[2] }}</span>
                    </div>
                    <ul class="list-unstyled text-start mb-4 flex-grow-1" style="font-size:14px;color:var(--text-secondary);line-height:2.2;">
                        @foreach($plan[3] as $feat)
                        <li><span style="color:var(--accent);font-weight:700;margin-right:8px;">&#10003;</span> {{ $feat }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('management.auth.register') }}" class="btn {{ $plan[4] ? 'btn-primary' : 'btn-ghost' }} w-100 justify-content-center">
                        {{ $plan[0] === 'Starter' ? 'Start Free' : 'Get Started' }}
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══ STORES ═══ --}}
@if(isset($stores) && $stores->isNotEmpty())
<section class="sec sec-alt">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Our Merchants</span>
                <h2 class="h2 mb-3">Stores powered by {{ $company->name }}</h2>
                <p class="body-lg">Discover businesses already growing with us.</p>
            </div>
        </div>
        <div class="row g-3">
            @foreach($stores as $store)
            <div class="col-sm-6 col-lg-4">
                <a href="{{ $store->slug ? route('home.store.products.index', ['store_subdomain' => $store->slug]) : route('home.stores') }}" class="store-tile h-100" target="_blank">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        @if($store->logo_path)
                        <img src="{{ asset('storage/'.$store->logo_path) }}" alt="{{ $store->name }}" style="width:44px;height:44px;border-radius:10px;object-fit:cover;border:1px solid var(--border);">
                        @else
                        <div style="width:44px;height:44px;border-radius:10px;background:var(--accent-soft);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;color:var(--accent);">{{ strtoupper(substr($store->name,0,2)) }}</div>
                        @endif
                        <div><strong style="color:#fff;">{{ $store->name }}</strong><div style="font-size:.8rem;color:var(--text-muted);">{{ $store->user?->name ?? $company->name }}</div></div>
                    </div>
                    @if($store->description)<p class="mb-0" style="font-size:.85rem;color:var(--text-muted);">{{ Str::limit($store->description, 90) }}</p>@endif
                </a>
            </div>
            @endforeach
        </div>
        <div class="text-center mt-5"><a href="{{ route('home.stores') }}" class="btn btn-ghost">View all stores &rarr;</a></div>
    </div>
</section>
@endif

{{-- ═══ TESTIMONIALS ═══ --}}
@if(isset($testimonials) && $testimonials->isNotEmpty())
<section class="sec">
    <div class="container">
        <div class="row justify-content-center text-center mb-5">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Testimonials</span>
                <h2 class="h2 mb-3">Trusted by Nigerian businesses</h2>
            </div>
        </div>
        <div class="row g-4">
            @foreach($testimonials as $t)
            <div class="col-md-6 col-lg-4">
                <div class="testimonial-card h-100">
                    <div style="color:var(--accent);font-size:28px;margin-bottom:12px;line-height:1;">&ldquo;</div>
                    <q class="mb-3 d-block">{{ $t->content }}</q>
                    <div class="mt-auto"><strong style="color:#fff;">{{ $t->name }}</strong>@if($t->role)<br><small style="color:var(--text-muted);">{{ $t->role }}</small>@endif</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ═══ FAQ ═══ --}}
<section class="sec sec-alt">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5">
                    <span class="label mb-3 d-block">Questions?</span>
                    <h2 class="h2 mb-3">Frequently Asked Questions</h2>
                </div>
                @php $faqs = [
                    ['What is '.$company->name.'?', $company->name.' is an all-in-one e-commerce and inventory management platform for Nigerian businesses. Run your online store, manage stock across warehouses, process POS sales, and accept payments — all from one place.'],
                    ['Is it really free?', 'Yes! The Starter plan is free forever — one store, up to 100 products, and a full online storefront at zero cost. Upgrade when your business is ready.'],
                    ['Can I use it for my physical store?', 'Absolutely. Our POS terminal lets you process walk-in sales from the same inventory as your online store. Everything syncs automatically.'],
                    ['What payment methods are supported?', 'Paystack (card payments), bank transfers with automatic verification, and cash payments through POS. Your customers can pay however they prefer.'],
                    ['Do I need technical skills?', 'Not at all. If you can fill out a form, you can run your store. We handle hosting, security, and updates.'],
                    ['Can I manage multiple stores?', 'Yes. Pro and Business plans support multiple stores and warehouses with role-based team access from one dashboard.'],
                ]; @endphp
                @foreach($faqs as $faq)
                <div class="faq-item">
                    <button class="faq-q">{{ $faq[0] }} <span class="faq-arrow">+</span></button>
                    <div class="faq-a">{{ $faq[1] }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══ CTA ═══ --}}
<section class="sec text-center" style="position:relative;overflow:hidden;">
    <div class="hero-glow" style="bottom:-200px;left:50%;transform:translateX(-50%);"></div>
    <div class="container position-relative" style="z-index:1;">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <span class="label mb-3 d-block">Ready to grow?</span>
                <h2 class="h2 mb-3">Start building your<br>business today</h2>
                <p class="body-lg mb-5 mx-auto" style="max-width:480px;">Create your free store. No credit card. Set up in minutes.</p>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="{{ route('management.auth.register') }}" class="btn btn-primary btn-lg">Get Started Free &rarr;</a>
                    <a href="{{ route('home.support') }}" class="btn btn-ghost btn-lg">Talk to Us</a>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
