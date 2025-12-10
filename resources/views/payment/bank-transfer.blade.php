@extends('home.layout')
@section('title', 'Bank Transfer Payment')

@section('content')
<br><br><br>
<div class="page-content">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="fa fa-university text-primary" style="font-size: 64px;"></i>
                            <h2 class="mt-3">Bank Transfer Payment</h2>
                            <p class="text-muted">Order #{{ $order->order_number }}</p>
                        </div>

                        <div class="alert alert-info">
                            <h5 class="alert-heading"><i class="fa fa-info-circle me-2"></i>Payment Instructions</h5>
                            <p class="mb-0">Transfer <strong>₦{{ number_format($paymentAmount, 2) }}</strong> to any of the bank accounts below. Use your <strong>Order ID ({{ $order->order_number }})</strong> as the payment reference.</p>
                        </div>

                        @if($order->source === 'live_first')
                        <div class="alert alert-success mb-4">
                            <h6><i class="fa fa-rocket me-2"></i>Live First Program - Down Payment</h6>
                            <p class="mb-1"><strong>Down Payment (10%):</strong> ₦{{ number_format($paymentAmount, 2) }}</p>
                            <p class="mb-0 small">Balance of ₦{{ number_format($order->total - $paymentAmount, 2) }} will be deducted from salary over 6 months.</p>
                        </div>
                        @endif

                        <h5 class="mb-3">Select a Bank Account</h5>
                        
                        @foreach($bankAccounts as $bankAccount)
                        <div class="card mb-3 border-primary">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    @if($bankAccount->logo)
                                    <div class="col-md-2 text-center">
                                        <img src="{{ Storage::url($bankAccount->logo) }}" alt="{{ $bankAccount->bank_name }}" style="max-height: 60px; width: auto;">
                                    </div>
                                    @endif
                                    <div class="col-md-{{ $bankAccount->logo ? '10' : '12' }}">
                                        <h5 class="mb-2">{{ $bankAccount->bank_name }}</h5>
                                        @if($bankAccount->account_name)
                                        <p class="mb-1"><strong>Account Name:</strong> {{ $bankAccount->account_name }}</p>
                                        @endif
                                        <p class="mb-0">
                                            <strong>Account Number:</strong> 
                                            <code class="fs-5 text-primary" id="account-{{ $bankAccount->id }}">{{ $bankAccount->account_number }}</code>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="copyAccountNumber('{{ $bankAccount->account_number }}', {{ $bankAccount->id }})" title="Click to copy">
                                                <i class="fa fa-copy"></i> Copy
                                            </button>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        <div class="alert alert-warning mt-4">
                            <h6><i class="fa fa-exclamation-triangle me-2"></i>Important Notes:</h6>
                            <ul class="mb-0 small">
                                <li>Please use <strong>{{ $order->order_number }}</strong> as your payment reference</li>
                                <li>After making the transfer, click "I've Paid" below</li>
                                <li>You can optionally attach a payment slip (screenshot or receipt)</li>
                                <li>Your order will be processed once payment is confirmed</li>
                            </ul>
                        </div>

                        <form method="POST" action="{{ route('payment.bank-transfer.confirm', ['store_slug' => $store->slug, 'order' => $order]) }}" enctype="multipart/form-data" class="mt-4">
                            @csrf

                            <div class="mb-4">
                                <label for="payment_slip" class="form-label">
                                    <i class="fa fa-paperclip me-1"></i>Attach Payment Slip (Optional)
                                </label>
                                <input type="file" 
                                       class="form-control @error('payment_slip') is-invalid @enderror" 
                                       id="payment_slip" 
                                       name="payment_slip" 
                                       accept=".jpg,.jpeg,.png,.heic,.pdf">
                                @error('payment_slip')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted">Accepted formats: JPG, PNG, HEIC, PDF (Max 5MB)</small>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fa fa-check-circle me-2"></i>I've Paid
                                </button>
                                <a href="{{ route('home.index') }}" class="btn btn-outline-secondary">
                                    <i class="fa fa-arrow-left me-2"></i>Back to Home
                                </a>
                            </div>
                        </form>

                        <div class="mt-4 text-center">
                            <p class="text-muted small mb-0">
                                <i class="fa fa-lock me-1"></i>Your transaction is secure and encrypted
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function copyAccountNumber(accountNumber, accountId) {
    // Create a temporary input element
    const tempInput = document.createElement('input');
    tempInput.value = accountNumber;
    document.body.appendChild(tempInput);
    
    // Select and copy the text
    tempInput.select();
    tempInput.setSelectionRange(0, 99999); // For mobile devices
    
    try {
        document.execCommand('copy');
        
        // Find the button that was clicked
        const button = event.target.closest('button');
        const originalHTML = button.innerHTML;
        
        // Show success feedback
        button.innerHTML = '<i class="fa fa-check"></i> Copied!';
        button.classList.remove('btn-outline-primary');
        button.classList.add('btn-success');
        
        // Reset button after 2 seconds
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-primary');
        }, 2000);
        
    } catch (err) {
        console.error('Failed to copy:', err);
        alert('Failed to copy account number. Please copy manually.');
    }
    
    // Remove the temporary input
    document.body.removeChild(tempInput);
}
</script>
@endpush

@endsection
