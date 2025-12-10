@extends('home.layout')

@section('title', 'Family Pack Review - ' . $familyPackOrder->pack_code)

@section('content')
<br>
<br>
<br>
<div class="container py-5">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Review Status -->
    @if($familyPackOrder->status === \App\Enums\FamilyPackStatus::PENDING_REVIEW)
        <div class="alert alert-info mb-4">
            <h5 class="alert-heading"><i class="bi bi-hourglass-split me-2"></i>Under Review</h5>
            <p class="mb-0">Your request has been submitted and is currently being reviewed by our team. We will notify you once the review is complete.</p>
        </div>
    @elseif($familyPackOrder->status === \App\Enums\FamilyPackStatus::APPROVED)
        <div class="alert alert-success mb-4">
            <h5 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Review Complete -> Order is being finanlized</h5>
            @if($familyPackOrder->last_updated_by !== 'customer')
                <hr>
                <form action="{{ route('family-pack.accept', ['store_slug' => $familyPackOrder->store->slug, 'packCode' => $familyPackOrder->pack_code]) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Accept & Proceed
                    </button>
                </form>
            @endif
        </div>
    @endif


    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('account.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Pack #{{ $familyPackOrder->pack_code }}</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="display-5 fw-bold">Pack Details</h1>
                <div class="d-flex align-items-center">
                    <span class="badge rounded-pill {{ $familyPackOrder->status->badgeClass() }} fs-6 px-3 py-2">
                        {{ $familyPackOrder->status->label() }}
                    </span>
                    <!-- @if($familyPackOrder->last_updated_by === 'customer')
                        <span class="badge rounded-pill bg-success fs-6 px-3 py-2 ms-2">You've accepted</span>
                    @endif -->
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Items List -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Pack Items</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($familyPackOrder->items as $item)
                                <tr>
                                    <td>
                                        <div class="fw-bold">{{ $item->product_name }}</div>
                                        @if($item->is_custom)
                                            <span class="badge bg-info text-dark">Custom Item</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-end">
                                        @if($item->unit_price)
                                            ₦{{ number_format($item->unit_price, 2) }}
                                        @else
                                            <span class="text-muted">Pending</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($item->subtotal > 0)
                                            ₦{{ number_format($item->subtotal, 2) }}
                                        @elseif($item->budgeted_amount > 0)
                                            <span class="text-muted">Est: ₦{{ number_format($item->budgeted_amount, 2) }}</span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end fw-bold">Subtotal</td>
                                <td class="text-end fw-bold">₦{{ number_format($familyPackOrder->subtotal, 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end">Shipping Fee</td>
                                <td class="text-end">₦{{ number_format($shippingFee, 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end">Tax ({{ $vatPercentage }}%)</td>
                                <td class="text-end">₦{{ number_format($tax, 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="text-end fw-bold fs-5">Total</td>
                                <td class="text-end fw-bold fs-5">₦{{ number_format($familyPackOrder->subtotal + $shippingFee + $tax, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Notes -->
            @if($familyPackOrder->notes || $familyPackOrder->review_notes)
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">Notes</h5>
                    </div>
                    <div class="card-body">
                        @if($familyPackOrder->notes)
                            <div class="mb-3">
                                <h6 class="fw-bold text-muted">Your Notes:</h6>
                                <p class="mb-0">{{ $familyPackOrder->notes }}</p>
                            </div>
                        @endif
                        
                        @if($familyPackOrder->review_notes)
                            <div class="alert alert-warning mb-0">
                                <h6 class="fw-bold"><i class="bi bi-person-badge me-2"></i>Admin Response:</h6>
                                <p class="mb-0">{{ $familyPackOrder->review_notes }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <!-- Pack Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-3">
                    <h5 class="mb-0">Pack Information</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Pack Code</dt>
                        <dd class="col-sm-7">{{ $familyPackOrder->pack_code }}</dd>

                        <dt class="col-sm-5">Type</dt>
                        <dd class="col-sm-7 text-capitalize">{{ $familyPackOrder->pack_type }}</dd>

                        <dt class="col-sm-5">Date</dt>
                        <dd class="col-sm-7">{{ $familyPackOrder->created_at->format('M d, Y') }}</dd>

                        @if($familyPackOrder->pack_type === 'recurring')
                            <dt class="col-sm-5">Payment</dt>
                            <dd class="col-sm-7">{{ $familyPackOrder->payment_interval }}</dd>

                            <dt class="col-sm-5">Delivery</dt>
                            <dd class="col-sm-7">{{ $familyPackOrder->deliveryInterval->name ?? 'Monthly' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <!-- Delivery Address -->
            <div class="card shadow-sm">
                <div class="card-header bg-light py-3">
                    <h5 class="mb-0">Delivery Address</h5>
                </div>
                <div class="card-body">
                    @if($familyPackOrder->deliveryAddress)
                        <p class="mb-0">{{ $familyPackOrder->deliveryAddress->full_address }}</p>
                    @else
                        <p class="text-muted mb-0">No address selected</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
