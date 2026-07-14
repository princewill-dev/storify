@extends('storefront.layout')
@section('title', 'Payment Failed')

@push('styles')
<style>
    .failed-card { background: #141417; border: 1px solid #27272a; border-radius: 20px; padding: 48px 40px; max-width: 480px; margin: 60px auto; text-align: center; position: relative; overflow: hidden; }
    .failed-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(239,68,68,0.15), transparent); }
    .icon-x { width: 64px; height: 64px; border-radius: 50%; background: rgba(239,68,68,.12); display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
    .icon-x svg { width: 32px; height: 32px; color: #ef4444; }
    .failed-card h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; color: #fff; }
    .failed-card p { color: #a1a1aa; font-size: 15px; line-height: 1.6; margin-bottom: 24px; }
    .details { background: #0e0e11; border: 1px solid #1f1f23; border-radius: 14px; padding: 20px; margin-bottom: 24px; text-align: left; }
    .row-item { display: flex; justify-content: space-between; align-items: center; }
    .row-item + .row-item { margin-top: 12px; padding-top: 12px; border-top: 1px solid #1f1f23; }
    .label { font-size: 12px; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
    .value { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .value.mono { font-family: SF Mono, Fira Code, monospace; font-size: 13px; color: #a1a1aa; }
    .value.red { color: #ef4444; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; font-size: 15px; font-weight: 600; padding: 14px 24px; border-radius: 12px; text-decoration: none; transition: all .2s; cursor: pointer; border: none; }
    .btn + .btn { margin-top: 10px; }
    .btn-primary { background: #a3e635; color: #0a0a0c; }
    .btn-primary:hover { background: #84cc16; transform: translateY(-1px); box-shadow: 0 0 24px rgba(163,230,53,0.2); }
    .btn-danger { background: #ef4444; color: #fff; }
    .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 0 24px rgba(239,68,68,0.3); }
    .btn-ghost { background: transparent; color: #a1a1aa; border: 1px solid #27272a; }
    .btn-ghost:hover { border-color: #52525b; color: #fff; }
    .tips { background: rgba(239,68,68,.06); border: 1px solid rgba(239,68,68,.15); border-radius: 12px; padding: 16px; margin-bottom: 24px; text-align: left; }
    .tips p { font-size: 13px; color: #f87171; margin-bottom: 8px; font-weight: 600; }
    .tips ul { margin: 0; padding-left: 18px; font-size: 12px; color: #a1a1aa; line-height: 1.8; }
</style>
@endpush

@section('content')
<div class="failed-card">
    <div class="icon-x">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
    </div>
    <h1>Payment Failed</h1>
    <p>We couldn't process your payment. Don't worry — you haven't been charged.</p>

    @if($order ?? null)
    <div class="details">
        <div class="row-item">
            <span class="label">Order</span>
            <span class="value mono">{{ $order->order_number }}</span>
        </div>
        <div class="row-item">
            <span class="label">Amount</span>
            <span class="value red">₦{{ number_format($order->total, 2) }}</span>
        </div>
        @if($transaction ?? null)
        <div class="row-item">
            <span class="label">Reference</span>
            <span class="value mono">{{ $transaction->reference }}</span>
        </div>
        @endif
    </div>

    <div class="tips">
        <p>Common reasons for failure:</p>
        <ul>
            <li>Insufficient funds in your account</li>
            <li>Incorrect card details entered</li>
            <li>Bank declined the transaction</li>
            <li>Network timeout during processing</li>
        </ul>
    </div>

    @php $pmRoute = app()->environment('local') ? 'local.checkout.payment-methods' : 'checkout.payment-methods'; @endphp
    <a href="{{ route($pmRoute, ['store_subdomain' => $store->slug, 'order' => $order->order_number]) }}" class="btn btn-danger">Try Again</a>
    <a href="{{ route('home.store.order.track', ['store_subdomain' => $store->slug, 'orderNumber' => $order->order_number]) }}" class="btn btn-ghost">View Order</a>
    @else
    <a href="{{ request()->getHost() === parse_url(config('app.url'), PHP_URL_HOST) ? url($store->slug) : url('/') }}" class="btn btn-primary">Continue Shopping</a>
    @endif
</div>
@endsection
