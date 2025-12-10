@extends('home.layout')
@section('title', 'Order Tracking - ' . $order->order_number)

@section('content')
<br>
<br>
<br>
<br>
<div class="tracking-page py-5 py-lg-6">
    <div class="container">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <a href="{{ route('tracking.index') }}" class="text-muted small text-decoration-none">
                    <i class="fa fa-arrow-left me-1"></i> Track another order
                </a>
                <h2 class="fw-bold mt-2 mb-1">Order {{ $order->order_number }} </h2>
                <!-- <h6>Status: <span class="badge bg-dark text-uppercase">{{ $order->status }}</span></h6> 
                <div class="text-muted small">Placed {{ $order->created_at->format('D, M j, Y \a\t h:i A') }}</div>
                <div class="text-muted small mt-2">Last updated {{ $order->updated_at->diffForHumans() }}</div> -->
            </div>
            <form method="GET" action="{{ route('tracking.index') }}" class="tracking-inline-form">
                <div class="input-group">
                    <input type="text" class="form-control" name="order" placeholder="Track another order" required>
                    <button class="btn btn-outline-success" type="submit">Track</button>
                </div>
            </form>
        </div>

        <div class="card shadow-sm border-0 mb-4 tracking-summary">
            <div class="card-body p-4 p-lg-5">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="status-dot {{ $order->status }}"></div>
                            <div>
                                <h5 class="fw-semibold mb-1">Status: <span class="badge bg-dark text-uppercase">{{ $order->status }}</span></h5>
                                <div class="text-muted small">Placed {{ $order->created_at->format('D, M j, Y \a\t h:i A') }}</div>
                                <div class="text-muted small mt-2">Last updated {{ $order->updated_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="border-start ps-4">
                            <div class="text-muted small text-uppercase mb-1">Delivery Route</div>
                            <div class="fw-semibold">{{ $order->deliveryRoute->state ?? 'N/A' }} -> {{ $order->deliveryRoute->area ?? 'N/A' }}</div>
                            @if($order->deliveryRoute->delivery_days)
                                <div class="text-muted small">Est. {{ $order->deliveryRoute->delivery_days }} day(s)</div>
                            @endif
                            <div class="text-muted small mt-2">Shipping fee: ₦{{ number_format($order->shipping_fee, 2) }}</div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="border-start ps-4">
                            <div class="text-muted small text-uppercase mb-1">Store</div>
                            <div class="fw-semibold">{{ $order->store?->name ?? 'Store' }}</div>
                            @if($order->vendor)
                                <div class="text-muted small">Handled by {{ $order->vendor->name }}</div>
                            @endif
                            <div class="text-muted small mt-2">Total paid: ₦{{ number_format($order->total, 2) }}</div>
                        </div>
                    </div>
                </div>

                <!-- <div class="milestone-track mt-5">
                    @foreach($milestones as $milestone)
                        <div class="milestone {{ $milestone['reached'] ? 'reached' : '' }} {{ $milestone['is_current'] ? 'current' : '' }}">
                            <div class="milestone-dot"></div>
                            <div class="milestone-label">{{ $milestone['label'] }}</div>
                        </div>
                    @endforeach
                </div> -->
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Order summary</h5>
                        <div class="d-flex justify-content-between small text-muted mb-2">
                            <span>Subtotal</span>
                            <span class="fw-semibold text-dark">₦{{ number_format($order->subtotal, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-2">
                            <span>Shipping</span>
                            <span class="fw-semibold text-dark">₦{{ number_format($order->shipping_fee, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-2">
                            <span>Tax</span>
                            <span class="fw-semibold text-dark">₦{{ number_format($order->tax, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
                            <span class="fw-semibold text-uppercase">Total</span>
                            <span class="fw-bold fs-5">₦{{ number_format($order->total, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Customer</h5>
                        <div class="small text-muted">Name</div>
                        <div class="fw-semibold mb-2">{{ $order->customer?->full_name ?? 'Guest' }}</div>
                        <div class="small text-muted">Email</div>
                        <div class="fw-semibold mb-2">{{ $order->customer?->email ?? '—' }}</div>
                        <div class="small text-muted">Phone</div>
                        <div class="fw-semibold mb-0">{{ $order->customer?->phone ?? '—' }}</div>
                    </div>
                </div> -->

                
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Shipping address</h5>
                        @php
                            $address = $order->deliveryAddress;
                        @endphp
                        <div class="small text-muted">Delivered to</div>
                        <div class="fw-semibold mb-2">
                            {{ $address?->recipient_name ?? $order->customer?->full_name ?? '—' }}
                        </div>
                        <div class="small text-muted">Address</div>
                        <div class="fw-semibold">
                            @if($address)
                                {{ $address->street_address }}
                                @if($address->apartment)
                                    , {{ $address->apartment }}
                                @endif
                                @if($address->deliveryRoute)
                                    , {{ $address->deliveryRoute->area ?? '' }}
                                    @if($address->deliveryRoute->state)
                                        , {{ $address->deliveryRoute->state }}
                                    @endif
                                    @if($address->deliveryRoute->country)
                                        , {{ $address->deliveryRoute->country }}
                                    @endif
                                @endif
                                @if($address->zip_code)
                                    , {{ $address->zip_code }}
                                @endif
                            @else
                                {{ $order->delivery_area ? $order->delivery_area . ', ' . $order->delivery_state : '—' }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Items in this order</h5>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit price</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($order->items as $item)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $item->product_name }}</div>
                                                <div class="small text-muted">Code: {{ $item->product_code }}</div>
                                            </td>
                                            <td class="text-center">{{ $item->quantity }}</td>
                                            <td class="text-end">₦{{ number_format($item->unit_price, 2) }}</td>
                                            <td class="text-end fw-semibold">₦{{ number_format($item->subtotal, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h5 class="fw-semibold mb-3">Tracking timeline</h5>
                        <div class="timeline">
                            @foreach($timeline as $log)
                                <div class="timeline-item">
                                    <div class="timeline-icon {{ $loop->first ? 'active' : '' }}">
                                        <i class="fa {{ $loop->first ? 'fa-check' : 'fa-circle' }}"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold">{{ $log->description ?? 'Update recorded' }}</div>
                                                @if($log->user)
                                                    <div class="small text-muted">by {{ $log->user->name }}</div>
                                                @endif
                                            </div>
                                            <div class="small text-muted">{{ $log->created_at->format('D, M j, Y \a\t h:i A') }}</div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div> -->
            </div>
        </div>
    </div>
</div>
<br>
<br>
<br>
<br>
@endsection

@push('styles')
<style>
.tracking-page {
    background: #f8fafc;
}
.tracking-inline-form .input-group {
    border-radius: 999px;
    background: #fff;
    overflow: hidden;
}
.status-dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #0ea5e9;
    margin-top: 6px;
}
.status-dot.delivered,
.status-dot.completed {
    background: #22c55e;
}
.status-dot.cancelled,
.status-dot.returned {
    background: #ef4444;
}
.milestone-track {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 1rem;
    position: relative;
}
.milestone {
    text-align: center;
    position: relative;
}
.milestone::before {
    content: '';
    position: absolute;
    top: 9px;
    left: -50%;
    width: 100%;
    height: 2px;
    background: #e2e8f0;
    z-index: 0;
}
.milestone:first-child::before {
    display: none;
}
.milestone-dot {
    width: 18px;
    height: 18px;
    margin: 0 auto 0.5rem;
    border-radius: 50%;
    background: #e2e8f0;
    position: relative;
    z-index: 1;
}
.milestone.reached .milestone-dot {
    background: #0f172a;
}
.milestone.current .milestone-dot {
    background: #22c55e;
}
.milestone-label {
    font-size: 0.85rem;
    color: #475569;
}
.timeline {
    position: relative;
    padding-left: 1.5rem;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e2e8f0;
}
.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}
.timeline-item:last-child {
    padding-bottom: 0;
}
.timeline-icon {
    position: absolute;
    left: -1px;
    top: 0;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #cbd5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 0.55rem;
}
.timeline-icon.active {
    border-color: #22c55e;
    color: #22c55e;
}
.timeline-content {
    margin-left: 1.75rem;
}
@media (max-width: 991px) {
    .tracking-inline-form {
        width: 100%;
    }
    .tracking-inline-form .input-group {
        border-radius: 12px;
    }
    .milestone-track {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 575px) {
    .milestone-track {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
}
</style>
@endpush
