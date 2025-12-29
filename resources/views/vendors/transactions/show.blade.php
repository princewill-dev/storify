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

    @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
    @endif

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
                    <hr>
                    <div class="d-flex gap-2 mt-3">
                        @if($transaction->payment_slip)
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentProofModal">
                            <i class="fa fa-file-image-o me-1"></i> View Payment Proof
                        </button>
                        @endif
                    </div>
                    @endif

                    <form action="{{ route('vendor.transactions.update', ['vendor' => $vendor, 'transaction' => $transaction]) }}" method="POST">
                        @csrf
                        @method('PUT')
                        
                        <div class="mb-3">
                            <label class="form-label small text-muted">Current Status</label>
                            <select name="status" class="form-select" required>
                                @php
                                    $currentStatus = $transaction->status;
                                    $statuses = \App\Enums\TransactionStatus::cases();
                                @endphp

                                {{-- Render current status first --}}
                                @if($currentStatus instanceof \App\Enums\TransactionStatus)
                                    <option value="{{ $currentStatus->value }}" selected>{{ $currentStatus->label() }}</option>
                                @endif
                                
                                {{-- Render other statuses --}}
                                @foreach($statuses as $status)
                                    @if($currentStatus instanceof \App\Enums\TransactionStatus && $status === $currentStatus)
                                        @continue
                                    @endif
                                    <option value="{{ $status->value }}" {{ !$currentStatus instanceof \App\Enums\TransactionStatus && $currentStatus == $status->value ? 'selected' : '' }}>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="alert alert-info small">
                            <i class="fa fa-info-circle me-1"></i> Changing this status will automatically update the order payment status.
                        </div>

                        <button type="submit" class="btn btn-dark btn-sm w-100">
                            <i class="fa fa-save"></i> Update Status
                        </button>
                    </form>

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


@endsection
