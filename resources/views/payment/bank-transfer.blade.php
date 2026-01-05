@extends('storefront.layout')
@section('title', 'Bank Transfer Payment')

@section('content')

<div class="page-content" style="background-color: #fafbfc; min-height: 80vh;">
    <br>
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-7">
                <div class="card border-0" style="box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-radius: 8px;">
                    <div class="card-body p-4 p-lg-5">
                        <!-- Header -->
                        <div class="mb-4">
                            <h5 class="fw-normal mb-1" style="color: #1a1a1a;">Complete your payment</h5>
                            <p class="text-muted small mb-0">Order {{ $order->order_number }}</p>
                        </div>

                        <!-- Amount Display -->
                        <div class="mb-4 pb-4" style="border-bottom: 1px solid #e6ebf1;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted" style="font-size: 14px;">Amount to pay</span>
                                <h4 class="mb-0 fw-semibold" style="color: #1a1a1a;">₦{{ number_format($paymentAmount, 2) }}</h4>
                            </div>
                        </div>

                        <!-- Bank Selection -->
                        <div class="mb-4">
                            <label class="form-label text-muted small mb-3">Select bank account</label>
                            
                            @foreach($bankAccounts as $bankAccount)
                            <div class="bank-card mb-2" data-bank-id="{{ $bankAccount->id }}">
                                <div class="bank-card-header" style="padding: 16px; border: 1px solid #e6ebf1; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="bank-radio" style="width: 18px; height: 18px; border: 2px solid #cbd5e0; border-radius: 50%; position: relative; transition: all 0.2s ease;">
                                                <div class="bank-radio-dot" style="width: 10px; height: 10px; background: #3b82f6; border-radius: 50%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); transition: transform 0.2s ease;"></div>
                                            </div>
                                            <span style="font-size: 15px; color: #1a1a1a; font-weight: 500;">{{ $bankAccount->bank_name }}</span>
                                        </div>
                                        <i class="fa fa-chevron-down expand-icon" style="font-size: 12px; color: #a0aec0; transition: transform 0.2s ease;"></i>
                                    </div>
                                </div>
                                <div class="bank-card-body" style="max-height: 0; overflow: hidden; transition: max-height 0.3s ease;">
                                    <div style="padding: 20px; background: #f7fafc; border: 1px solid #e6ebf1; border-top: none; border-radius: 0 0 6px 6px;">
                                        @if($bankAccount->account_name)
                                        <div class="mb-3">
                                            <div class="text-muted small mb-1">Account name</div>
                                            <div style="font-size: 14px; color: #1a1a1a;">{{ $bankAccount->account_name }}</div>
                                        </div>
                                        @endif
                                        <div class="mb-3">
                                            <div class="text-muted small mb-1">Account number</div>
                                            <div class="d-flex align-items-center gap-2">
                                                <code class="bg-white px-3 py-2 border" style="font-size: 16px; color: #1a1a1a; border-radius: 4px; letter-spacing: 0.5px;">{{ $bankAccount->account_number }}</code>
                                                <button type="button" class="btn btn-sm btn-light copy-btn" onclick="copyAccountNumber('{{ $bankAccount->account_number }}', {{ $bankAccount->id }})" style="border: 1px solid #e6ebf1;">
                                                    <i class="fa fa-copy" style="font-size: 12px;"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="fa fa-info-circle me-1"></i>
                                            Use order number <strong>{{ $order->order_number }}</strong> as reference
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <!-- Payment Form -->
                        <form method="POST" action="{{ route('payment.bank-transfer.confirm', ['store_subdomain' => $store->slug, 'order' => $order]) }}" enctype="multipart/form-data" id="paymentForm">
                            @csrf
                            <input type="hidden" name="store_bank_id" id="store_bank_id" value="">

                            <div class="mb-4">
                                <label for="payment_slip" class="form-label text-muted small mb-2">
                                    Proof of payment (optional)
                                </label>
                                <input type="file" 
                                       class="form-control @error('payment_slip') is-invalid @enderror" 
                                       id="payment_slip" 
                                       name="payment_slip" 
                                       accept=".jpg,.jpeg,.png,.heic,.pdf"
                                       style="border: 1px solid #e6ebf1; padding: 10px; font-size: 14px;">
                                @error('payment_slip')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <small class="text-muted" style="font-size: 12px;">JPG, PNG, HEIC, or PDF (Max 5MB)</small>
                            </div>

                            <button type="submit" class="btn w-100 btn-lg mb-3" style="background: #1a1a1a; color: white; border: none; padding: 14px; font-size: 15px; font-weight: 500; border-radius: 6px; transition: all 0.2s ease;">
                                Confirm Payment
                            </button>
                            
                            <div class="text-center">
                                <small class="text-muted" style="font-size: 12px;">
                                    <i class="fa fa-lock me-1"></i>Secure payment
                                </small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
.bank-card.selected .bank-card-header {
    border-color: #3b82f6;
    background: #eff6ff;
}
.bank-card.selected .bank-radio {
    border-color: #3b82f6;
}
.bank-card.selected .bank-radio-dot {
    transform: translate(-50%, -50%) scale(1);
}
.bank-card.expanded .expand-icon {
    transform: rotate(180deg);
}
.bank-card-header:hover {
    background: #f7fafc;
}
.copy-btn:hover {
    background: #f7fafc !important;
}
button[type="submit"]:hover {
    background: #2d2d2d !important;
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const bankCards = document.querySelectorAll('.bank-card');
    
    bankCards.forEach(card => {
        const header = card.querySelector('.bank-card-header');
        const body = card.querySelector('.bank-card-body');
        
        header.addEventListener('click', function() {
            // Remove selected class from all cards
            bankCards.forEach(c => {
                c.classList.remove('selected', 'expanded');
                const b = c.querySelector('.bank-card-body');
                b.style.maxHeight = '0px';
            });
            
            // Add selected class to clicked card
            card.classList.add('selected', 'expanded');
            body.style.maxHeight = body.scrollHeight + 'px';
            
            // Update hidden input
            const bankId = card.getAttribute('data-bank-id');
            document.getElementById('store_bank_id').value = bankId;
        });
    });
    
    // Auto-select first bank
    if (bankCards.length > 0) {
        bankCards[0].querySelector('.bank-card-header').click();
    }
});

function copyAccountNumber(accountNumber, accountId) {
    const tempInput = document.createElement('input');
    tempInput.value = accountNumber;
    document.body.appendChild(tempInput);
    tempInput.select();
    tempInput.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        const button = event.target.closest('button');
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fa fa-check" style="font-size: 12px;"></i>';
        setTimeout(() => {
            button.innerHTML = originalHTML;
        }, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
    }
    
    document.body.removeChild(tempInput);
}

// Show success modal on form submission
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Processing...';
    
    // Submit form via AJAX
    const formData = new FormData(this);
    
    fetch(this.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect_url;
        } else {
            alert('An error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    })
    .catch((error) => {
        console.error('Error:', error);
        // Show modal anyway as fallback
        const modal = document.getElementById('successModal');
        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
        
        setTimeout(() => {
            window.location.href = "{{ route('tracking.show', ['order' => $order->order_number]) }}";
        }, 3000);
    });
});
</script>
@endpush

@endsection
