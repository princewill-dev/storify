@extends('storefront.layout')

@section('title', 'Pay with Paystack')

@section('content')
<br>
<br>
<br>
<div class="page-content">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fa fa-credit-card me-2"></i>Pay with Paystack</h4>
                    </div>
                    <div class="card-body">
                        @if($order)
                            <div class="order-summary mb-4">
                                <h5>Order Summary</h5>
                                <hr>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Order Code:</span>
                                    <strong>{{ $order->order_code }}</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Amount:</span>
                                    <strong class="text-primary">₦{{ number_format($order->total, 2) }}</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge bg-warning">{{ $order->payment_status->label() ?? ucfirst($order->payment_status->value) }}</span>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <i class="fa fa-info-circle me-2"></i>
                                You will be redirected to Paystack's secure payment page. After successful payment, you'll be redirected back to our site for confirmation.
                            </div>

                            <div id="error-message" class="alert alert-danger d-none" role="alert"></div>

                            <button 
                                type="button" 
                                id="pay-button" 
                                class="btn btn-primary btn-lg w-100"
                                onclick="payWithPaystack()"
                            >
                                <i class="fa fa-lock me-2"></i>Proceed to Payment
                            </button>

                            <div class="mt-3 text-center">
                                <small class="text-muted">
                                    <i class="fa fa-shield-alt me-1"></i>
                                    Secured by Paystack. Your payment information is encrypted.
                                </small>
                            </div>
                        @else
                            <div class="alert alert-danger">
                                <i class="fa fa-exclamation-triangle me-2"></i>
                                Order not found. Please try again.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="{{ route('home.index') }}" class="text-muted">
                        <i class="fa fa-arrow-left me-1"></i>Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function payWithPaystack() {
        const button = document.getElementById('pay-button');
        const errorDiv = document.getElementById('error-message');
        
        // Disable button
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        errorDiv.classList.add('d-none');

        // Initialize payment
        fetch('{{ route("payment.paystack.initialize") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                order_id: {{ $order->id ?? 'null' }},
                email: '{{ $order->email ?? auth("customer")->user()->email ?? "" }}'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.authorization_url) {
                // Redirect to Paystack payment page
                window.location.href = data.data.authorization_url;
            } else {
                throw new Error(data.message || 'Failed to initialize payment');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = error.message || 'An error occurred. Please try again.';
            errorDiv.classList.remove('d-none');
            
            // Re-enable button
            button.disabled = false;
            button.innerHTML = '<i class="fa fa-lock me-2"></i>Proceed to Payment';
        });
    }
</script>
@endpush
@endsection
