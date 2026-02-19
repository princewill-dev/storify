<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }} - @yield('subtitle')</title>
    <link rel="shortcut icon" type="image/png" href="{{ $company->favicon }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body {
            min-height: 100vh;
            background: #f7f7f7;
            color: #111827;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(2rem, 3vw, 3rem) 1rem;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .auth-card {
            width: min(1120px, 100%);
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 12px 32px rgba(17, 24, 39, 0.08);
            padding: clamp(1.75rem, 2vw + 1rem, 2.75rem);
            position: relative;
        }

        .cancel-x-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: none;
            background: #fee2e2;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .2s, transform .15s;
            z-index: 10;
            padding: 0;
        }
        .cancel-x-btn:hover {
            background: #fecaca;
            transform: scale(1.1);
        }

        .auth-logo {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, #4b5563, #111827);
            color: #ffffff;
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
        }

        .auth-heading {
            text-align: center;
            margin-bottom: 1rem;
        }

        .auth-heading h1 {
            font-size: 1.65rem;
            margin-bottom: .25rem;
        }

        .auth-heading p {
            color: #6b7280;
            margin-bottom: 0;
        }

        .form-control-lg {
            border-radius: 14px;
            padding: 0.85rem 1rem;
        }

        .btn-primary {
            border-radius: 14px;
            padding: 0.85rem 1rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            background: linear-gradient(135deg, #6b7280, #111827);
            border: none;
        }

        .btn-outline-secondary {
            border-radius: 14px;
        }

        .auth-footer {
            margin-top: 1.75rem;
            text-align: center;
            color: #94a3b8;
            font-size: .9rem;
        }

        /* ===== Mobile Responsive Fixes ===== */
        @media (max-width: 767.98px) {
            .auth-card {
                width: 100%;
                max-width: 480px;
                padding: 1.5rem 1rem;
                border-radius: 18px;
            }

            .form-control-lg,
            .form-select-lg {
                font-size: 0.95rem;
                padding: 0.7rem 0.875rem;
                border-radius: 10px;
            }

            .btn-lg {
                font-size: 0.95rem;
                padding: 0.7rem 1rem;
                border-radius: 10px;
            }

            .form-label {
                font-size: 0.875rem;
            }

            h1, .auth-heading h1 {
                font-size: 1.35rem;
            }

            h2, h3 {
                font-size: 1.25rem;
            }

            .auth-heading p {
                font-size: 0.9rem;
            }
        }

        @media (min-width: 768px) and (max-width: 991.98px) {
            .auth-card {
                max-width: 720px;
            }
        }
    </style>
    @stack('scripts')
</head>
<body>
    <main class="auth-card">
        {{-- Red X cancel button at top-right of card --}}
        @if(auth('vendor')->check() && !in_array(Route::currentRouteName(), ['vendor.register', 'vendor.verify-email']))
            <button type="button" class="cancel-x-btn" title="Cancel onboarding" data-bs-toggle="modal" data-bs-target="#cancelOnboardingModal">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                </svg>
            </button>
        @endif

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('status'))
            <div class="alert alert-primary alert-dismissible fade show" role="alert">
                {{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>We found a few issues:</strong>
                <ul class="mb-0 mt-2 small">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Show onboarding stepper for onboarding pages --}}
        @if(auth('vendor')->check() && !in_array(Route::currentRouteName(), ['vendor.register', 'vendor.verify-email']))
            @include('vendors.auth.partials.onboarding-stepper')
        @endif

        @yield('content')

        
    </main>

    <div class="auth-footer" style="margin-top: auto; padding-top: 2rem; padding-bottom: 1rem;">
        <a href="{{ route('home.index') }}" class="text-muted text-decoration-none">Home</a>
        <br>
        <small class="text-muted">&copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.</small>
    </div>

    {{-- Cancel Onboarding Modal --}}
    <div class="modal fade" id="cancelOnboardingModal" tabindex="-1" aria-labelledby="cancelOnboardingLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="cancelOnboardingLabel">
                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>Cancel Onboarding?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Are you sure you want to cancel the onboarding process?</p>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Don't worry - your progress has been saved! You can continue from where you left off when you log in again.
                    </p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>Continue Setup
                    </button>
                    <form action="{{ route('vendor.auth.logout') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt me-1"></i>Yes, Cancel Process
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>