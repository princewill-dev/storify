<div class="nav-wrap">
    <div class="nav-inner">
        <a href="{{ route('home.index') }}" class="nav-logo"><img src="{{ $company->logo }}" alt="{{ $company->name }}"></a>
        <div class="nav-links" id="navLinks">
            <a href="#features">Features</a>
            <a href="#how-it-works">How It Works</a>
            <a href="#pricing">Pricing</a>
            <a href="{{ route('home.about') }}">About</a>
            <a href="{{ route('management.auth.login') }}">Login</a>
            <a href="{{ route('management.auth.register') }}" class="nav-cta">Get Started</a>
        </div>
        <button class="nav-toggle" id="navToggle" aria-label="Menu">&#9776;</button>
    </div>
    <div class="nav-mobile" id="navMobile">
        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>
        <a href="#pricing">Pricing</a>
        <a href="{{ route('home.about') }}">About</a>
        <a href="{{ route('management.auth.login') }}">Login</a>
        <a href="{{ route('management.auth.register') }}" class="nav-cta">Get Started</a>
    </div>
</div>
