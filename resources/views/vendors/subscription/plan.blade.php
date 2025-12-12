@extends('vendors.auth.layout')

@section('title', 'Subscription Plan')
@section('subtitle', 'Complete your subscription to activate your vendor account')

@section('content')

    <header class="auth-heading">
        <h1>@yield('title', 'Subscription Plan')</h1>
        <p>@yield('subtitle')</p>
    </header>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-primary">{{ $plan->name }}</h3>
                @if($plan->description)
                    <p class="text-muted">{{ $plan->description }}</p>
                @endif
            </div>

            <div class="text-center mb-4">
                <div class="display-4 fw-bold text-dark">
                    {{ $plan->currency }} {{ number_format($plan->amount, 2) }}
                </div>
                <p class="text-muted">per {{ $plan->interval }}</p>
            </div>

            @if($plan->features && is_array($plan->features) && count($plan->features) > 0)
                <div class="mb-4">
                    <h5 class="fw-semibold mb-3">Features Included:</h5>
                    <ul class="list-unstyled">
                        @foreach($plan->features as $feature)
                            <li class="mb-2">
                                <i class="bi bi-check-circle-fill text-success me-2"></i>
                                {{ $feature }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="alert alert-info mb-4">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Note:</strong> A yearly subscription payment is required to use our vendor services. 
                Your subscription will be active immediately after successful payment.
            </div>

            <form method="POST" action="{{ route('vendor.subscription.initialize', ['vendor' => $vendor]) }}" id="subscriptionForm">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg w-100" id="payButton">
                    <i class="bi bi-credit-card me-2"></i>
                    Make Payment
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">
                    <i class="bi bi-shield-check me-1"></i>
                    Secure payment powered by Paystack
                </small>
            </div>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="{{ route('vendor.dashboard') }}" class="text-muted">
            <i class="bi bi-arrow-left me-1"></i>
            Return to Dashboard
        </a>
    </div>

@endsection

@push('scripts')
<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('subscriptionForm');
        const payButton = document.getElementById('payButton');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            payButton.disabled = true;
            payButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            form.submit();
        });
    });
</script>
@endpush
