<footer class="ftr">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-5 mb-4 mb-md-0">
                <div class="mb-3">
                    <img src="{{ $company->logo }}" alt="{{ $company->name }}" style="height:26px;filter:brightness(0) invert(1);">
                </div>
                <p style="font-size:14px;max-width:300px;line-height:1.7;">The complete business management platform for Nigerian retail businesses. Sell online, in-store, and everywhere in between.</p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" style="width:34px;height:34px;border-radius:8px;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .15s;">𝕏</a>
                    <a href="#" style="width:34px;height:34px;border-radius:8px;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:14px;transition:all .15s;">IG</a>
                    <a href="#" style="width:34px;height:34px;border-radius:8px;background:var(--bg-elevated);display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .15s;">in</a>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <h6>Product</h6>
                <ul>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#pricing">Pricing</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="{{ route('home.stores') }}">Stores</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-2">
                <h6>Company</h6>
                <ul>
                    <li><a href="{{ route('home.about') }}">About Us</a></li>
                    <li><a href="{{ route('home.support') }}">Contact</a></li>
                    <li><a href="#">Careers</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-3">
                <h6>Resources</h6>
                <ul>
                    <li><a href="{{ route('home.support') }}">Help Center</a></li>
                    <li><a href="{{ route('tracking.index') }}">Track Order</a></li>
                    <li><a href="#">Blog</a></li>
                    <li><a href="#">API Docs</a></li>
                </ul>
            </div>
        </div>
        <hr>
        <div class="row align-items-center">
            <div class="col-md-6 mb-3 mb-md-0">
                <p class="mb-0" style="font-size:13px;">&copy; {{ date('Y') }} {{ $company->name }}. All rights reserved.</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="#" class="me-3" style="font-size:13px;">Privacy</a>
                <a href="#" class="me-3" style="font-size:13px;">Terms</a>
                <a href="#" style="font-size:13px;">Cookies</a>
            </div>
        </div>
    </div>
</footer>
