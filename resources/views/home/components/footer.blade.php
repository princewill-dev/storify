<footer class="ftr">
    <div class="container">
        <div class="row g-4">
            <div class="col-6 col-md-3"><h6>Platform</h6><ul><li><a href="#features">Features</a></li><li><a href="{{ route('home.stores') }}">Stores</a></li><li><a href="{{ route('home.about') }}">About</a></li></ul></div>
            <div class="col-6 col-md-3"><h6>Resources</h6><ul><li><a href="{{ route('home.support') }}">Support</a></li><li><a href="{{ route('tracking.index') }}">Track Order</a></li><li><a href="{{ route('management.auth.register') }}">Become a Vendor</a></li></ul></div>
            <div class="col-6 col-md-3"><h6>Company</h6><ul><li><a href="{{ route('home.about') }}">About Us</a></li><li><a href="{{ route('home.support') }}">Contact</a></li></ul></div>
            <div class="col-6 col-md-3"><h6>Legal</h6><ul><li><a href="#">Terms of Service</a></li><li><a href="#">Privacy Policy</a></li></ul></div>
        </div>
        <hr>
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center"><p class="mb-2 mb-sm-0">&copy; {{ date('Y') }} {{ $company->name }}. All rights reserved.</p><div><a href="#" class="me-3">Twitter</a><a href="#" class="me-3">Facebook</a><a href="#">Instagram</a></div></div>
    </div>
</footer>
