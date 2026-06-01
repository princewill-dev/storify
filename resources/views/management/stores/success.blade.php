@extends('management.layout')

@section('subtitle', 'Store created successfully')

@section('content')
    <div class="text-center mb-5">
        <div class="mx-auto mb-4">
            <span style="font-size: 5rem;">🎉</span>
            <br>
            <br>
            @if ($store->logo_path)
                <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }} logo" width="200px">
            @endif
        </div>

        <h2 class="fw-bold mb-2">{{ $store->name }}</h2>

        @if ($storeUrl)
            <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-light border mb-4">
                <a href="{{ $storeUrl }}" target="_blank" rel="noopener" class="text-decoration-none fw-medium text-dark">
                    {{ $storeUrl }}
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('{{ $storeUrl }}')">
                    copy
                </button>
            </div>
        @endif

        @php
            // Smart detection: Check if setup is complete
            $hasPaymentMethod = $store->banks()->exists() || $store->paymentGateways()->exists();
            $hasDeliveryRoutes = $store->deliveryRoutes()->exists();
            $hasSubscription = $user->business?->hasActiveSubscription();
            
            // Onboarding is complete if they have subscription
            $onboardingComplete = $hasSubscription;
        @endphp

        @if($onboardingComplete)
            {{-- Final success state - onboarding complete --}}
            <p class="text-muted mb-5">🎊 Congratulations! Your store is fully set up and ready to go. Start adding products and managing your business!</p>
            <a href="{{ route('management.dashboard') }}" class="btn btn-dark btn-lg px-5">Go to Dashboard</a>
        @else
            {{-- Intermediate success - continue setup --}}
            <p class="text-muted mb-5">Yay! Your store is ready to go. Let's continue setting it up.</p>
            <a href="{{ route('management.payment-methods.form') }}" class="btn btn-dark btn-lg px-5">Continue Setup</a>
        @endif
    </div>
@endsection
