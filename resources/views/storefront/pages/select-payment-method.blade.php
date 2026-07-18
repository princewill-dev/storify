@extends('storefront.layout')
@section('title', 'Payment Method')

@section('content')
<section style="padding:80px 0;background:#f8fafc;min-height:100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9">
                <div style="text-align:center;margin-bottom:32px;">
                    <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:16px;background:#f1f5f9;margin-bottom:16px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 4px;">Payment Method</h2>
                    <p style="font-size:14px;color:#64748b;margin:0;">Choose how you'd like to pay</p>
                </div>

                <div style="background:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span style="font-size:13px;font-weight:600;color:#0f172a;">Order #{{ $order->order_number }}</span>
                            <span style="font-size:12px;color:#94a3b8;margin-left:8px;">{{ $order->items->count() }} item{{ $order->items->count() !== 1 ? 's' : '' }}</span>
                        </div>
                        <span style="font-size:20px;font-weight:700;color:#0f172a;">₦{{ number_format($paymentAmount, 2) }}</span>
                    </div>
                </div>

                @if(session('error'))
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#b91c1c;">{{ session('error') }}</div>
                @endif
                @if(session('success'))
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#16a34a;">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#b91c1c;">
                    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
                @endif

                <form method="POST" action="{{ route('checkout.payment-methods.select', ['store_subdomain' => $store->slug, 'order' => $order->order_number]) }}">
                    @csrf

                    <div style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">Available Methods</div>

                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px;">
                        @forelse($paymentMethods as $method)
                        <label class="payment-option {{ $loop->first ? 'selected' : '' }}" style="display:flex;align-items:center;gap:14px;padding:18px 20px;border:2px solid {{ $loop->first ? '#0f172a' : '#e2e8f0' }};border-radius:14px;cursor:pointer;transition:all 0.15s;background:{{ $loop->first ? '#f8fafc' : '#fff' }};">
                            <input type="radio" name="payment_method" value="{{ $method->code }}" {{ $loop->first ? 'checked' : '' }} required style="display:none;">
                            <span class="payment-radio-dot" style="display:flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;border:2px solid {{ $loop->first ? '#0f172a' : '#cbd5e1' }};flex-shrink:0;transition:all 0.15s;">
                                <span style="display:{{ $loop->first ? 'block' : 'none' }};width:10px;height:10px;border-radius:50%;background:#0f172a;"></span>
                            </span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:14px;font-weight:600;color:#0f172a;">{{ $method->name }}</div>
                                @if($method->description)
                                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">{{ $method->description }}</div>
                                @endif
                            </div>
                            <span style="color:{{ $method->type === 'gateway' ? '#6366f1' : '#0f172a' }};flex-shrink:0;">
                                @if($method->type === 'gateway')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                                @else
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11m16-11v11M8 14v3m4-5v5m4-3v3"/></svg>
                                @endif
                            </span>
                        </label>
                        @empty
                        <div style="text-align:center;padding:40px 20px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom:16px;"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            <h5 style="font-size:15px;font-weight:600;color:#0f172a;margin:0 0 4px;">No payment methods available</h5>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">This store hasn't set up payment methods yet. Please contact the store owner.</p>
                        </div>
                        @endforelse
                    </div>

                    @if($paymentMethods->isNotEmpty())
                    <button type="submit" style="display:flex;align-items:center;justify-content:center;width:100%;padding:16px;background:#0f172a;color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:600;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">
                        Continue to Payment
                    </button>
                    @endif
                </form>
            </div>
        </div>
    </div>
</section>

<script>
document.querySelectorAll('.payment-option').forEach(option => {
    option.addEventListener('click', function() {
        document.querySelectorAll('.payment-option').forEach(o => {
            o.style.borderColor = '#e2e8f0';
            o.style.background = '#fff';
            o.classList.remove('selected');
            const dot = o.querySelector('.payment-radio-dot');
            dot.style.borderColor = '#cbd5e1';
            dot.querySelector('span').style.display = 'none';
        });
        this.style.borderColor = '#0f172a';
        this.style.background = '#f8fafc';
        this.classList.add('selected');
        const dot = this.querySelector('.payment-radio-dot');
        dot.style.borderColor = '#0f172a';
        dot.querySelector('span').style.display = 'block';
        this.querySelector('input[type="radio"]').checked = true;
    });
});
</script>
@endsection
