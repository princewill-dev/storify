@extends('vendors.auth.layout')

@section('subtitle', 'Store created successfully')

@section('content')
    <div class="text-center mb-5">
        <div class="mx-auto mb-4">
            <span style="font-size: 5rem;">🎉</span>
            <br>
            <br>
            @if ($store->logo_path)
                <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }} logo" width="200px">
            @else
                <span class="text-muted">No logo</span>
            @endif
        </div>

        <h2 class="fw-bold mb-2">{{ $store->name }}</h2>
        <!-- <p class="text-muted mb-3">Store ID: <span class="fw-semibold">{{ $store->store_id }}</span></p> -->

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

        <p class="text-muted mb-5">Yay! Your store is ready to go. You can now start adding products and managing your store.</p>

        <a href="{{ route('vendor.dashboard') }}" class="btn btn-dark btn-lg px-5">Go to dashboard</a>
    </div>
@endsection
