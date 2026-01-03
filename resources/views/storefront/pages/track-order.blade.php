@extends('storefront.layout')
@section('title', 'Track Order')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <h2 class="text-center mb-4">Track Your Order</h2>
            
            <div class="card shadow-sm mb-5">
                <div class="card-body p-4">
                    <form action="{{ route('home.store.order.find', ['store_subdomain' => $store->slug]) }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="order_number" class="form-label">Order Number</label>
                            <input type="text" class="form-control form-control-lg" id="order_number" name="order_number" placeholder="e.g. ORD-XXXXXXXXXX" required value="{{ old('order_number', $order->order_number ?? '') }}">
                        </div>
                        <button type="submit" class="btn btn-dark w-100 btn-lg">Track Order</button>
                    </form>
                    
                    @if(session('error'))
                        <div class="alert alert-danger mt-3">
                            {{ session('error') }}
                        </div>
                    @endif
                </div>
            </div>

            @if(isset($order))
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h4 class="mb-0">Order Details</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">#{{ $order->order_number }}</h5>
                            <span class="badge {{ $order->status_badge_class }} fs-6">{{ $order->status_label }}</span>
                        </div>
                        
                        <p class="text-muted mb-4">Placed on {{ $order->created_at->format('F d, Y') }}</p>

                        <h6 class="border-bottom pb-2 mb-3">Items</h6>
                        @foreach($order->items as $item)
                            <div class="d-flex justify-content-between mb-2">
                                <div>
                                    <span class="fw-medium">{{ $item->product_name }}</span>
                                    @if($item->variant_options)
                                        <div class="text-muted small">{{ $item->variant_options }}</div>
                                    @endif
                                    <div class="text-muted small">Qty: {{ $item->quantity }}</div>
                                </div>
                                <span>{{ $item->formatted_amount }}</span>
                            </div>
                        @endforeach
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span>{{ number_format($order->subtotal, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Shipping</span>
                            <span>{{ number_format($order->shipping_fee, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold mt-3 fs-5">
                            <span>Total</span>
                            <span>{{ number_format($order->total, 2) }}</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
