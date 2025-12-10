@extends('home.layout')
@section('title', 'Live First - Buy Now, Pay Later')

@section('content')
<br>
<br>
<br>
<br>
<div class="live-first-hero py-5 py-lg-6">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <div class="hero-copy">
                    <span class="badge bg-success text-uppercase mb-3">Live First Program</span>
                    <h1 class="display-5 fw-bold mb-3" style="color: #0D775E;">Buy Now, Pay Later</h1>
                    <p class="lead text-muted mb-4">Shop for your essentials today and pay later! Our Live First program allows verified salary earners to purchase groceries on credit and repay monthly from their salary.</p>
                    <ul class="list-unstyled text-muted mb-4">
                        <li class="d-flex align-items-start mb-3">
                            <i class="fa fa-check-circle text-success me-3 mt-1" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong class="text-dark">Pay only 10% upfront</strong>
                                <p class="mb-0 small">Get your groceries immediately by paying just 10% of the total cost</p>
                            </div>
                        </li>
                        <li class="d-flex align-items-start mb-3">
                            <i class="fa fa-check-circle text-success me-3 mt-1" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong class="text-dark">Automatic deduction</strong>
                                <p class="mb-0 small">Convenient automatic deduction from your salary each month</p>
                            </div>
                        </li>
                        <li class="d-flex align-items-start mb-3">
                            <i class="fa fa-check-circle text-success me-3 mt-1" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong class="text-dark">Build your credit</strong>
                                <p class="mb-0 small">Qualify after 6 months of consistent payments</p>
                            </div>
                        </li>
                    </ul>

                    @auth('customer')
                        @if($currentStatus->value === 'not_enrolled')
                            <a href="{{ route('home.live-first.kyc', ['store_slug' => $store->slug]) }}" class="btn btn-success btn-lg px-5 py-3 shadow-sm">
                                <i class="fa fa-rocket me-2"></i> Enroll Now
                            </a>
                        @elseif($currentStatus->value === 'pending_verification')
                            <a href="{{ route('home.live-first.status', ['store_slug' => $store->slug]) }}" class="btn btn-warning btn-lg px-5 py-3 shadow-sm">
                                <i class="fa fa-clock me-2"></i> View Application Status
                            </a>
                        @elseif(in_array($currentStatus->value, ['verified', 'testing', 'tested']))
                            <a href="{{ route('home.live-first.status', ['store_slug' => $store->slug]) }}" class="btn btn-info btn-lg px-5 py-3 shadow-sm">
                                <i class="fa fa-chart-line me-2"></i> View Progress
                            </a>
                        @elseif($currentStatus->value === 'approved')
                            <a href="{{ route('home.store.products.index', ['store_slug' => $store->slug]) }}" class="btn btn-success btn-lg px-5 py-3 shadow-sm">
                                <i class="fa fa-shopping-cart me-2"></i> Start Shopping on Credit
                            </a>
                        @elseif($currentStatus->value === 'suspended')
                            <div class="alert alert-danger">
                                <i class="fa fa-exclamation-triangle me-2"></i> Your account has been suspended. Please contact support.
                            </div>
                        @endif
                    @endauth

                    @guest('customer')
                        <a href="{{ route('account.login', ['flow' => 'live-first', 'store_slug' => $store->slug]) }}" class="btn btn-success btn-lg px-5 py-3 shadow-sm me-3">
                            <i class="fa fa-sign-in-alt me-2"></i> Login to Enroll
                        </a>
                        <a href="{{ route('account.register', ['flow' => 'live-first', 'store_slug' => $store->slug]) }}" class="btn btn-outline-success btn-lg px-5 py-3">
                            <i class="fa fa-user-plus me-2"></i> Register
                        </a>
                    @endguest
                </div>
            </div>
            <div class="col-lg-5 ms-lg-auto">
                <div class="info-card shadow">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-4">How It Works</h5>
                        <div class="steps">
                            <div class="step-item mb-4">
                                <div class="d-flex">
                                    <div class="step-number">1</div>
                                    <div class="step-content">
                                        <h6 class="fw-semibold mb-1">Complete KYC</h6>
                                        <p class="text-muted small mb-0">Submit your employment and identity documents for verification</p>
                                    </div>
                                </div>
                            </div>
                            <div class="step-item mb-4">
                                <div class="d-flex">
                                    <div class="step-number">2</div>
                                    <div class="step-content">
                                        <h6 class="fw-semibold mb-1">Build Trust (6 Months)</h6>
                                        <p class="text-muted small mb-0">Shop normally and pay on time for 6 consecutive months</p>
                                    </div>
                                </div>
                            </div>
                            <div class="step-item">
                                <div class="d-flex">
                                    <div class="step-number">3</div>
                                    <div class="step-content">
                                        <h6 class="fw-semibold mb-1">Get Approved</h6>
                                        <p class="text-muted small mb-0">Start shopping on credit with automatic salary deduction</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top">
                            <h6 class="fw-semibold mb-3">Required Documents</h6>
                            <ul class="small text-muted mb-0" style="line-height: 1.8;">
                                <li>National ID (NIN) or Passport</li>
                                <li>Recent & old (2+ years) payslips</li>
                                <li>Employment appointment letter</li>
                                <li>Bank authorization letter</li>
                                <li>Selfie photo & verification video</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<br>
<br>
<br>
<br>
@endsection

@push('styles')
<style>
.live-first-hero {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    min-height: 80vh;
}
.hero-copy h1 {
    color: #0D775E;
}
.info-card {
    background: #fff;
    border-radius: 18px;
    border: none;
}
.step-item {
    position: relative;
}
.step-number {
    width: 40px;
    height: 40px;
    background: #0D775E;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 15px;
    flex-shrink: 0;
}
.step-content {
    flex: 1;
}
.btn-lg {
    border-radius: 12px;
}
</style>
@endpush
