@extends('vendors.auth.layout')
@section('subtitle', 'Create vendor account')

@section('content')
    <style>
        body {
            background: #f7f7f7;
        }

        .auth-card {
            background: transparent;
            box-shadow: none;
            padding: clamp(1.75rem, 2vw + 1.2rem, 3rem);
            width: min(1120px, 100%);
        }

        .vendor-onboard {
            display: flex;
            flex-direction: column;
            gap: clamp(1.5rem, 2vw, 2rem);
        }

        .vendor-onboard .onboard-grid {
            display: grid;
            gap: clamp(1.5rem, 2vw, 2.25rem);
        }

        @media (min-width: 992px) {
            .vendor-onboard .onboard-grid {
                grid-template-columns: minmax(0, 1fr) 320px;
                align-items: stretch;
            }
        }

        .vendor-onboard .onboard-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 18px 45px rgba(17, 24, 39, 0.08);
            padding: clamp(1.75rem, 2vw + 1rem, 2.6rem);
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            height: 100%;
        }

        .vendor-onboard .form-intro p:last-of-type {
            margin-bottom: 0;
        }

        .vendor-onboard .onboard-steps {
            background: #e5e7eb;
            border-radius: 16px;
            padding: 0.75rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }

        @media (min-width: 768px) {
            .vendor-onboard .onboard-steps {
                justify-content: flex-start;
            }
        }

        .vendor-onboard .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.9rem;
            border-radius: 14px;
            background: transparent;
            color: #4b5563;
            font-weight: 600;
        }

        .vendor-onboard .step-pill.active {
            background: #ffffff;
            color: #111827;
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.12);
        }

        .vendor-onboard .step-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 12px;
            background: #d1d5db;
            color: #1f2937;
            font-weight: 700;
        }

        .vendor-onboard .step-pill.active .step-index {
            background: #4b5563;
            color: #ffffff;
        }

        .vendor-onboard .form-control-lg {
            border-radius: 12px;
            border-color: #e2e8f0;
        }

        .vendor-onboard .form-control-lg:focus {
            border-color: #4b5563;
            box-shadow: 0 0 0 0.25rem rgba(75, 85, 99, 0.15);
        }

        .vendor-onboard .btn-primary {
            border-radius: 12px;
            background: linear-gradient(135deg, #6b7280, #111827);
        }

        .vendor-onboard .onboard-benefits {
            margin-bottom: 0;
        }

        .vendor-onboard .benefit-icon {
            width: 30px;
            height: 30px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6b7280, #111827);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(17, 24, 39, 0.18);
        }

        .vendor-onboard .onboard-proof {
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 991.98px) {
            .vendor-onboard .onboard-card {
                border-radius: 18px;
            }
        }

        @media (max-width: 767.98px) {
            .vendor-onboard .onboard-card {
                padding: 1.25rem;
            }

            .vendor-onboard .form-control-lg,
            .vendor-onboard .form-select-lg {
                font-size: 0.95rem;
                padding: 0.65rem 0.75rem;
            }

            .vendor-onboard h2 {
                font-size: 1.25rem;
            }
        }
    </style>
    <section class="vendor-onboard">
        <div class="onboard-card form-card">
                <div class="form-intro">
                    <p class="text-uppercase text-muted fw-semibold small mb-2">Join the marketplace</p>
                    <h2 class="fw-bold mb-3">Lets setup your account</h2>
                </div>

                <form method="POST" action="{{ route('vendor.auth.register.store') }}" class="row g-3">
                    @csrf

                    <div class="col-12">
                        <label for="full_name" class="form-label fw-semibold">Full name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="form-control form-control-lg @error('full_name') is-invalid @enderror"
                            placeholder="e.g. John Doe"
                            value="{{ old('full_name') }}"
                            required
                        >
                        @error('full_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="email" class="form-label fw-semibold">Your email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control form-control-lg @error('email') is-invalid @enderror"
                            placeholder="you@email.com"
                            value="{{ old('email') }}"
                            required
                        >
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="phone" class="form-label fw-semibold">Your phone number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="form-control form-control-lg @error('phone') is-invalid @enderror"
                            placeholder="Enter a reachable phone number"
                            value="{{ old('phone') }}"
                            required
                        >
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control form-control-lg @error('password') is-invalid @enderror"
                            placeholder="Create a strong password"
                            required
                        >
                        <small class="text-muted">Minimum 8 characters.</small>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="password_confirmation" class="form-label fw-semibold">Confirm password</label>
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            class="form-control form-control-lg"
                            placeholder="Re-enter password"
                            required
                        >
                    </div>

                    <div class="col-12 d-grid mt-2">
                        <br>
                        <button type="submit" class="btn btn-primary btn-lg">Create account</button>
                    </div>
                </form>

                <div class="text-center pt-2">
                    <span class="text-muted">Already a vendor?</span>
                    <a class="link-primary text-decoration-none" href="{{ route('vendor.auth.login') }}">Sign in</a>
                </div>
            </div>
    </section>
@endsection
