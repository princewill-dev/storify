@extends('vendors.layout')
@section('subtitle', 'Transaction ' . $transaction->reference)

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">ID: {{ $transaction->reference }}</h1>
                    <p class="text-muted">Date: {{ $transaction->created_at->format('d M Y h:i A') }}</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" class="btn btn-outline-secondary">
                        <i class="fa fa-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Reference</span>
                        <strong>{{ $transaction->reference }}</strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <p class="text-muted mb-0">Status</p>
                            <span class="badge {{ $transaction->status_badge_class }}">{{ $transaction->status_label }}</span>
                        </div>
                        <div class="text-end">
                            <p class="text-muted mb-0">Amount</p>
                            <strong class="text-success">₦{{ number_format($transaction->amount, 2) }}</strong>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <p class="text-muted mb-1">Payment method</p>
                            <p class="mb-0">{{ $transaction->paymentMethod->name ?? 'N/A' }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Account:</p>
                            <p class="mb-0">{{ $transaction->storeBank->bank_name ?? 'N/A' }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Created</p>
                            <p class="mb-0">{{ $transaction->created_at->format('d M Y h:i A') }}</p>
                        </div>
                        <div class="col-6">
                            <p class="text-muted mb-1">Paid at</p>
                            <p class="mb-0">{{ optional($transaction->paid_at)->format('d M Y h:i A') ?? 'Pending' }}</p>
                        </div>
                    </div>

                    @if($transaction->payment_slip || $transaction->status->value === 'pending')
                    <div class="d-flex gap-2 mt-3">
                        @if($transaction->payment_slip)
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentProofModal">
                            <i class="fa fa-file-image-o me-1"></i> View Payment Proof
                        </button>
                        @endif
                    </div>
                    @endif


                    @if($transaction->status->value === 'pending')
                    <hr>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-success btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#confirmPaymentModal">
                            <i class="fa fa-check-circle me-1"></i> Confirm Payment
                        </button>
                        <button type="button" class="btn btn-danger btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#rejectPaymentModal">
                            <i class="fa fa-times-circle me-1"></i> Reject Payment
                        </button>
                    </div>
                    @endif

                    @if($transaction->status->value === 'confirmed')
                    <hr>
                    <div class="mt-3">
                        <button type="button" class="btn btn-warning btn-sm w-100" data-bs-toggle="modal" data-bs-target="#refundPaymentModal">
                            <i class="fa fa-undo me-1"></i> Refund Payment
                        </button>
                    </div>
                    @endif

                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Order & Customer</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-1">Order</p>
                    @if($transaction->order)
                    <p>
                        <a href="{{ route('vendor.orders.show', ['vendor' => $vendor, 'order' => $transaction->order]) }}" class="fw-bold">#{{ $transaction->order->order_number }}</a>
                        @if($transaction->order->store)
                        <span class="text-muted">({{ $transaction->order->store->name }})</span>
                        @endif
                    </p>
                    @else
                    <p class="text-muted">Not attached to an order</p>
                    @endif

                    <hr>

                    <p class="text-muted mb-1">Customer</p>
                    @if($transaction->order && $transaction->order->customer)
                    <p class="mb-1">{{ $transaction->order->customer->full_name }}</p>
                    <p class="text-muted mb-0">{{ $transaction->order->customer->email }}</p>
                    @else
                    <p class="text-muted">N/A</p>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>



@if($transaction->payment_slip)
    <div class="modal fade" id="paymentProofModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Proof</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-0">
                    @php
                        $extension = pathinfo($transaction->payment_slip, PATHINFO_EXTENSION);
                    @endphp
                    
                    @if(in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'heic', 'webp']))
                        <img src="{{ Storage::url($transaction->payment_slip) }}" alt="Payment Slip" class="img-fluid">
                    @elseif(strtolower($extension) === 'pdf')
                        <iframe src="{{ Storage::url($transaction->payment_slip) }}" style="width:100%; height:500px;" frameborder="0"></iframe>
                    @else
                        <div class="p-5">
                            <p class="mb-3">File type not previewable directly.</p>
                            <a href="{{ Storage::url($transaction->payment_slip) }}" target="_blank" class="btn btn-primary">
                                Download File
                            </a>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <a href="{{ Storage::url($transaction->payment_slip) }}" download class="btn btn-outline-secondary">
                        <i class="fa fa-download"></i> Download
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif


{{-- Confirm Payment Modal --}}
<div class="modal fade" id="confirmPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fa fa-check-circle me-2"></i>Confirm Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('vendor.transactions.confirm', ['vendor' => $vendor, 'transaction' => $transaction]) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-success mb-3">
                        <strong>Are you sure you want to confirm this payment?</strong>
                    </div>
                    
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Transaction ID:</span>
                                <strong>{{ $transaction->reference }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Order Number:</span>
                                <strong>{{ $transaction->order->order_number ?? 'N/A' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Amount:</span>
                                <strong class="text-success">₦{{ number_format($transaction->amount, 2) }}</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0">
                        <small>
                            <i class="fa fa-info-circle me-1"></i>
                            This action will:
                            <ul class="mb-0 mt-2">
                                <li>Credit ₦{{ number_format($transaction->amount, 2) }} to your store balance</li>
                                <li>Send a confirmation email to the customer</li>
                                <li>Update the transaction status to "Confirmed"</li>
                            </ul>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-check me-1"></i> Yes, Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Reject Payment Modal --}}
<div class="modal fade" id="rejectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fa fa-times-circle me-2"></i>Reject Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('vendor.transactions.reject', ['vendor' => $vendor, 'transaction' => $transaction]) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <strong>Are you sure you want to reject this payment?</strong>
                    </div>
                    
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Transaction ID:</span>
                                <strong>{{ $transaction->reference }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Order Number:</span>
                                <strong>{{ $transaction->order->order_number ?? 'N/A' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Amount:</span>
                                <strong>₦{{ number_format($transaction->amount, 2) }}</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Rejection Reason <span class="text-muted">(Optional)</span></label>
                        <textarea 
                            class="form-control" 
                            id="rejectionReason" 
                            name="reason" 
                            rows="3" 
                            maxlength="500" 
                            placeholder="Provide a reason for rejecting this payment (optional, but recommended)"
                        ></textarea>
                        <div class="form-text">This reason will be shared with the customer via email.</div>
                    </div>
                    
                    <div class="alert alert-danger mb-0">
                        <small>
                            <i class="fa fa-exclamation-triangle me-1"></i>
                            This action will:
                            <ul class="mb-0 mt-2">
                                <li>Send a rejection email to the customer</li>
                                <li>Update the transaction status to "Canceled"</li>
                                <li>No balance will be credited to your store</li>
                            </ul>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fa fa-times me-1"></i> Yes, Reject Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Refund Payment Modal --}}
<div class="modal fade" id="refundPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fa fa-undo me-2"></i>Refund Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('vendor.transactions.refund', ['vendor' => $vendor, 'transaction' => $transaction]) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-warning mb-3">
                        <strong>Are you sure you want to refund this payment?</strong>
                    </div>
                    
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Transaction ID:</span>
                                <strong>{{ $transaction->reference }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Order Number:</span>
                                <strong>{{ $transaction->order->order_number ?? 'N/A' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Order Status:</span>
                                <span class="badge {{ $transaction->order->status->badgeClass() }}">
                                    {{ $transaction->order->status->label() }}
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Amount to Refund:</span>
                                <strong class="text-danger">₦{{ number_format($transaction->amount, 2) }}</strong>
                            </div>
                        </div>
                    </div>
                    
                    @php
                        $orderStatus = $transaction->order->status;
                        $isDeliveredOrCompleted = in_array($orderStatus, [\App\Enums\OrderStatus::DELIVERED, \App\Enums\OrderStatus::COMPLETED]);
                    @endphp
                    
                    @if($isDeliveredOrCompleted)
                    <div class="alert alert-danger mb-3">
                        <strong><i class="fa fa-exclamation-triangle me-1"></i> Warning!</strong><br>
                        This order is marked as <strong>{{ $orderStatus->label() }}</strong>. You must change the order status to <strong>"Returned"</strong> before processing a refund.
                    </div>
                    @endif
                    
                    <div class="mb-3">
                        <label for="refundReason" class="form-label">Refund Reason <span class="text-danger">*</span></label>
                        <textarea 
                            class="form-control" 
                            id="refundReason" 
                            name="reason" 
                            rows="3" 
                            maxlength="500" 
                            placeholder="Provide a reason for this refund (required)"
                            required
                        ></textarea>
                        <div class="form-text">This reason will be shared with the customer via email.</div>
                    </div>
                    
                    <div class="alert alert-warning mb-0">
                        <small>
                            <i class="fa fa-exclamation-circle me-1"></i>
                            This action will:
                            <ul class="mb-0 mt-2">
                                <li><strong>Debit ₦{{ number_format($transaction->amount, 2) }}</strong> from your store balance</li>
                                <li>Send a refund notification email to the customer</li>
                                <li>Update the transaction status to "Refunded"</li>
                            </ul>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fa fa-undo me-1"></i> Yes, Process Refund
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
