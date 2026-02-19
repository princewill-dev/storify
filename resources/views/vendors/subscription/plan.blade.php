@extends('vendors.auth.layout')

@section('subtitle', 'Choose Your Plan')

@section('content')

    <header class="auth-heading">
        <h1>Choose Your Plan</h1>
        <p>Select a plan to activate your vendor account</p>
    </header>

    <div class="row g-4 justify-content-center">
        @foreach($plans as $plan)
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 {{ $plan->is_trial ? 'border-success' : '' }}" style="border-radius: 16px; {{ $plan->is_trial ? 'border: 2px solid #22c55e !important;' : '' }}">
                    <div class="card-body p-4 d-flex flex-column">

                        {{-- Badge --}}
                        @if($plan->is_trial)
                            <span class="badge bg-success-subtle text-success mb-3 align-self-start" style="font-size: .8rem; padding: 6px 12px; border-radius: 8px;">
                                🎉 Free Trial
                            </span>
                        @else
                            <span class="badge bg-dark-subtle text-dark mb-3 align-self-start" style="font-size: .8rem; padding: 6px 12px; border-radius: 8px;">
                                ⭐ Recommended
                            </span>
                        @endif

                        {{-- Plan name --}}
                        <h5 class="fw-bold mb-2">{{ $plan->name }}</h5>

                        @if($plan->description)
                            <p class="text-muted small mb-3">{{ $plan->description }}</p>
                        @endif

                        {{-- Price --}}
                        <div class="mb-3">
                            @if($plan->is_trial)
                                <div class="d-flex align-items-baseline gap-1">
                                    <span class="display-6 fw-bold text-success">Free</span>
                                </div>
                                <small class="text-muted">for {{ $plan->trial_days }} days</small>
                            @else
                                <div class="d-flex align-items-baseline gap-1">
                                    <span class="display-6 fw-bold">{{ $plan->currency }} {{ number_format($plan->amount, 2) }}</span>
                                </div>
                                <small class="text-muted">{{ $plan->interval }}</small>
                            @endif
                        </div>

                        {{-- Features --}}
                        @if($plan->features && is_array($plan->features) && count($plan->features) > 0)
                            <ul class="list-unstyled mb-4 flex-grow-1">
                                @foreach($plan->features as $feature)
                                    <li class="mb-2 d-flex align-items-start gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="{{ $plan->is_trial ? '#22c55e' : '#111827' }}" viewBox="0 0 16 16" class="flex-shrink-0 mt-1">
                                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                                        </svg>
                                        <span class="small">{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- CTA Button --}}
                        @if($plan->is_trial)
                            <form method="POST" action="{{ route('vendor.subscription.activate-trial', ['vendor' => $vendor]) }}">
                                @csrf
                                <button type="submit" class="btn btn-success btn-lg w-100" style="border-radius: 12px;">
                                    Start Free Trial
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('vendor.subscription.initialize', ['vendor' => $vendor]) }}" id="subscriptionForm">
                                @csrf
                                <button type="submit" class="btn btn-dark btn-lg w-100" id="payButton" style="border-radius: 12px;">
                                    Make Payment
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Secondary Actions --}}
    <div class="d-flex justify-content-center align-items-center gap-4 mt-4 flex-wrap">
        <button type="button" class="btn btn-link text-muted p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#earlyPassModal">
            <i class="bi bi-ticket-perforated me-1"></i>
            Have a coupon code?
        </button>
    </div>

    {{-- Security badge --}}
    <div class="text-center mt-3">
        <small class="text-muted">
            <i class="bi bi-shield-check me-1"></i>
            Secure payment powered by Paystack
        </small>
    </div>

    <!-- Early Pass Modal -->
    <div class="modal fade" id="earlyPassModal" tabindex="-1" aria-labelledby="earlyPassModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="earlyPassModalLabel">
                        <i class="bi bi-ticket-perforated me-2 text-primary"></i>
                        Enter Coupon Code
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="text-muted mb-3">Enter your early access code to activate your account without payment.</p>
                    
                    <div class="mb-3">
                        <input type="text" 
                               id="earlyPassCode" 
                               class="form-control form-control-lg text-center text-uppercase" 
                               placeholder="ENTER CODE"
                               maxlength="50"
                               autocomplete="off">
                    </div>
                    
                    <!-- Feedback area -->
                    <div id="earlyPassFeedback" class="text-center" style="display: none;">
                        <small id="earlyPassMessage" class="d-flex align-items-center justify-content-center gap-2"></small>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="applyCodeBtn">
                        Apply Code
                    </button>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Payment form handling
        const form = document.getElementById('subscriptionForm');
        const payButton = document.getElementById('payButton');

        if (form && payButton) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                payButton.disabled = true;
                payButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                form.submit();
            });
        }

        // Early Pass handling
        const earlyPassInput = document.getElementById('earlyPassCode');
        const applyCodeBtn = document.getElementById('applyCodeBtn');
        const earlyPassFeedback = document.getElementById('earlyPassFeedback');
        const earlyPassMessage = document.getElementById('earlyPassMessage');
        const checkUrl = '{{ route("vendor.subscription.check-early-pass", ["vendor" => $vendor]) }}';
        const csrfToken = '{{ csrf_token() }}';

        function setButtonLoading(loading) {
            if (loading) {
                applyCodeBtn.disabled = true;
                applyCodeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking code...';
            } else {
                applyCodeBtn.disabled = false;
                applyCodeBtn.innerHTML = 'Apply Code';
            }
        }

        function showFeedback(type, message) {
            earlyPassFeedback.style.display = 'block';
            
            let icon = '';
            let colorClass = '';
            
            switch (type) {
                case 'checking':
                    icon = '<span class="spinner-border spinner-border-sm"></span>';
                    colorClass = 'text-muted';
                    break;
                case 'success':
                    icon = '<svg width="16" height="16" fill="currentColor" class="text-success" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>';
                    colorClass = 'text-success';
                    break;
                case 'error':
                    icon = '<svg width="16" height="16" fill="currentColor" class="text-danger" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/></svg>';
                    colorClass = 'text-danger';
                    break;
            }
            
            earlyPassMessage.className = 'd-flex align-items-center justify-content-center gap-2 ' + colorClass;
            earlyPassMessage.innerHTML = icon + ' <span>' + message + '</span>';
        }

        function hideFeedback() {
            earlyPassFeedback.style.display = 'none';
        }

        function checkEarlyPass() {
            const code = earlyPassInput.value.trim();
            
            if (!code) {
                showFeedback('error', 'Please enter a code');
                return;
            }

            setButtonLoading(true);
            showFeedback('checking', 'Checking code...');

            fetch(checkUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ code: code })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFeedback('success', data.message);
                    applyCodeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Redirecting...';
                    
                    setTimeout(() => {
                        window.location.href = data.redirect_url;
                    }, 1000);
                } else {
                    showFeedback('error', data.message);
                    setButtonLoading(false);
                }
            })
            .catch(error => {
                console.error('Early pass check error:', error);
                showFeedback('error', 'An error occurred. Please try again.');
                setButtonLoading(false);
            });
        }

        applyCodeBtn.addEventListener('click', checkEarlyPass);

        earlyPassInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                checkEarlyPass();
            }
        });

        const modal = document.getElementById('earlyPassModal');
        modal.addEventListener('show.bs.modal', function() {
            earlyPassInput.value = '';
            hideFeedback();
            setButtonLoading(false);
        });

        modal.addEventListener('shown.bs.modal', function() {
            earlyPassInput.focus();
        });
    });
</script>
@endpush
