@extends('storefront.layout')
@section('title', 'Payment Successful')

@push('styles')
<style>
    .success-card {
        background: #141417; border: 1px solid #27272a; border-radius: 20px;
        padding: 48px 40px; max-width: 480px; margin: 60px auto; text-align: center;
        position: relative; overflow: hidden;
    }
    .success-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent); }
    .check { width: 64px; height: 64px; border-radius: 50%; background: rgba(34,197,94,.12); display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
    .check svg { width: 32px; height: 32px; color: #22c55e; }
    .success-card h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; color: #fff; }
    .success-card p { color: #a1a1aa; font-size: 15px; line-height: 1.6; margin-bottom: 24px; }
    .details { background: #0e0e11; border: 1px solid #1f1f23; border-radius: 14px; padding: 20px; margin-bottom: 24px; text-align: left; }
    .row { display: flex; justify-content: space-between; align-items: center; }
    .row + .row { margin-top: 12px; padding-top: 12px; border-top: 1px solid #1f1f23; }
    .label { font-size: 12px; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
    .value { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .value.mono { font-family: SF Mono, Fira Code, monospace; font-size: 13px; color: #a1a1aa; }
    .value.green { color: #22c55e; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; font-size: 15px; font-weight: 600; padding: 14px 24px; border-radius: 12px; text-decoration: none; transition: all .2s; cursor: pointer; border: none; }
    .btn + .btn { margin-top: 10px; }
    .btn-primary { background: #a3e635; color: #0a0a0c; }
    .btn-primary:hover { background: #84cc16; transform: translateY(-1px); box-shadow: 0 0 24px rgba(163,230,53,0.2); }
    .btn-ghost { background: transparent; color: #a1a1aa; border: 1px solid #27272a; }
    .btn-ghost:hover { border-color: #52525b; color: #fff; }
</style>
@endpush

@section('content')
<div class="success-card">
    <div class="check">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
    </div>
    <h1>Payment Successful</h1>
    <p>Your payment has been verified and your order is being processed.</p>

    @if($order ?? null)
    <div class="details">
        <div class="row">
            <span class="label">Order</span>
            <span class="value mono">{{ $order->order_number }}</span>
        </div>
        <div class="row">
            <span class="label">Amount Paid</span>
            <span class="value green">₦{{ number_format($order->total, 2) }}</span>
        </div>
        @if($transaction ?? null)
        <div class="row">
            <span class="label">Reference</span>
            <span class="value mono">{{ $transaction->reference }}</span>
        </div>
        @endif
    </div>
    @endif

    <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug ?? 'store', 'orderNumber' => $order->order_number]) }}" class="btn btn-primary">Track Order</a>
    <a href="{{ request()->getHost() === parse_url(config('app.url'), PHP_URL_HOST) ? url($store->slug) : url('/') }}" class="btn btn-ghost">Continue Shopping</a>
</div>
@endsection
