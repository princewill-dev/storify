@extends('tracking.layout')
@section('title', 'Track Your Order')

@section('content')

<div class="tracking-hero py-5 py-lg-6">
    <br>
    <div class="container">
        <div class="row g-5 align-items-center">
            
            <div class="col-lg-5">
                <div class="tracking-card shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Track your order</h5>
                        <form method="GET" action="{{ route('tracking.index') }}" class="tracking-form">
                            <div class="mb-3">
                                <label class="form-label text-muted">Tracking number / Order ID</label>
                                <input type="text" name="order" value="{{ old('order', $prefillReference) }}" class="form-control form-control-lg @if($error) is-invalid @endif" placeholder="e.g. ORD-9F2QXY1K" required>
                                @if($error)
                                    <div class="invalid-feedback">{{ $error }}</div>
                                @endif
                            </div>
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="fa fa-search me-2"></i> Track Order
                            </button>
                        </form>
                        <div class="mt-4 small text-muted">
                            <i class="fa fa-info-circle me-1"></i>
                            Tracking information is updated instantly when the status of your order changes.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="tracking-hero__copy">
                    <span class="badge bg-light text-uppercase text-muted mb-3">Track a package</span>
                    <h1 class="display-5 fw-bold mb-3">Stay updated on your delivery</h1>
                    <p class="lead text-muted mb-4">Enter your order tracking number to see live updates on the status of your package, expected delivery and activity timeline.</p>
                    <ul class="list-unstyled text-muted small mb-0">
                        <li class="d-flex align-items-start mb-2"><i class="fa fa-circle-check text-success me-2 mt-1"></i>View real-time status across every milestone</li>
                        <li class="d-flex align-items-start mb-2"><i class="fa fa-circle-check text-success me-2 mt-1"></i>See delivery estimates and shipping information</li>
                        <li class="d-flex align-items-start"><i class="fa fa-circle-check text-success me-2 mt-1"></i>Track multiple orders by entering each tracking ID</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <br>
    <br>
    <br>
    <br>
</div>

@endsection

@push('styles')
<style>
.tracking-hero {
    background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
}
.tracking-hero__copy h1 {
    color: #0f172a;
}
.tracking-card {
    background: #fff;
    border-radius: 18px;
}
.tracking-card .form-control {
    border-radius: 10px;
    padding: 0.85rem 1rem;
    font-size: 1rem;
}
.tracking-card .btn {
    border-radius: 10px;
}
</style>
@endpush
