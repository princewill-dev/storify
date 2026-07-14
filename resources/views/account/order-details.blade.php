@extends('account.layout')
@section('title', 'Order ' . $order->order_number)
@section('subtitle', $order->order_number)

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Items</h6>
                <span class="badge-status bg-secondary bg-opacity-10 text-secondary">{{ $order->status->label() }}</span>
            </div>
            <div class="p-0">
                @foreach($order->items as $item)
                <div class="d-flex justify-content-between align-items-center px-4 py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div>
                        <p class="mb-0 fw-medium">{{ $item->product_name }}</p>
                        <small class="text-muted">Qty: {{ $item->quantity }} × ₦{{ number_format($item->unit_price, 2) }}</small>
                    </div>
                    <span class="fw-semibold">₦{{ number_format($item->subtotal, 2) }}</span>
                </div>
                @endforeach
                <div class="d-flex justify-content-between px-4 py-3 bg-light fw-bold">
                    <span>Total</span>
                    <span>₦{{ number_format($order->total, 2) }}</span>
                </div>
            </div>
        </div>

        @if($order->transactions->count() > 0)
        <div class="card">
            <div class="px-4 py-3 border-bottom"><h6 class="mb-0 fw-semibold">Payment</h6></div>
            <div class="p-0">
                @foreach($order->transactions as $tx)
                <div class="d-flex justify-content-between align-items-center px-4 py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div>
                        <p class="mb-0 small fw-medium">{{ $tx->reference }}</p>
                        <small class="text-muted">{{ $tx->created_at->format('M d, Y H:i') }}</small>
                    </div>
                    <div class="text-end">
                        <span class="fw-semibold">₦{{ number_format($tx->amount, 2) }}</span><br>
                        <small class="text-muted">{{ $tx->status->label() }}</small>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="px-4 py-3 border-bottom"><h6 class="mb-0 fw-semibold">Details</h6></div>
            <div class="p-4">
                <div class="mb-2 d-flex justify-content-between"><small class="text-muted">Store</small><span>{{ $order->store->name }}</span></div>
                <div class="mb-2 d-flex justify-content-between"><small class="text-muted">Date</small><span class="small">{{ $order->created_at->format('M d, Y H:i') }}</span></div>
                <div class="mb-2 d-flex justify-content-between"><small class="text-muted">Subtotal</small><span>₦{{ number_format($order->subtotal, 2) }}</span></div>
                @if($order->shipping_fee > 0)<div class="mb-2 d-flex justify-content-between"><small class="text-muted">Shipping</small><span>₦{{ number_format($order->shipping_fee, 2) }}</span></div>@endif
                @if($order->tax > 0)<div class="mb-2 d-flex justify-content-between"><small class="text-muted">Tax</small><span>₦{{ number_format($order->tax, 2) }}</span></div>@endif
            </div>
        </div>
        <a href="{{ route('account.orders') }}" class="btn btn-outline btn-sm w-100">← Back to Orders</a>
    </div>
</div>
@endsection
