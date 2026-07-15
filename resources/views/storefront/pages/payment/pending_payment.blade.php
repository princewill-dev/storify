@extends('storefront.layout')
@section('title', 'Payment Verification Pending')

@section('content')
<div class="page-content" style="background-color: #fafbfc; min-height: 80vh; display: flex; align-items: center; justify-content: center;">
    <div class="container">
        <div class="row justify-content-center py-5">
            <div class="col-lg-6">
                <div class="card border-0" style="box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 12px;">
                    <div class="card-body text-center p-5">
                        <div class="mb-4">
                            <div style="width: 80px; height: 80px; background: #ecfdf5; border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                                <i class="fa fa-check" style="font-size: 40px; color: #10b981;"></i>
                            </div>
                        </div>

                        <h4 class="mb-3" style="color: #1a1a1a; font-weight: 600;">{{ $store->name }} is verifying your payment</h4>
                        
                        <p class="text-muted mb-4" style="font-size: 16px;">
                            We have received your payment submission for order <br>
                            <div class="d-inline-flex align-items-center gap-2 mt-2">
                                <strong id="orderNumber">#{{ $order->order_number }}</strong>
                                <button type="button" class="btn btn-sm btn-light p-1 lh-1" onclick="copyOrderNumber({{ Js::from($order->order_number) }}, this)" style="border: 1px solid #e2e8f0;" title="Copy Order Number">
                                    <i class="fa fa-copy" style="font-size: 12px; color: #64748b;"></i>
                                </button>
                            </div>
                            <br><span class="d-block mt-2">Your order is currently being processed.</span>
                        </p>
                        
                        <div class="d-grid gap-3 col-lg-8 mx-auto">
                            <a href="{{ route('home.store.order.track', ['store_subdomain' => $order->store->slug, 'orderNumber' => $order->order_number]) }}" class="btn btn-primary btn-lg" style="background: #1a1a1a; border: none; padding: 12px;">
                                View Order Status
                            </a>
                            <a href="{{ route('home.store.products.index', ['store_subdomain' => $store->slug]) }}" class="btn btn-light" style="color: #64748b;">
                                Back to Shop
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function copyOrderNumber(orderNumber, btnElement) {
        // Fallback or Clipboard API
        const copyText = () => {
             // Show feedback
            const originalIcon = btnElement.innerHTML;
            
            btnElement.innerHTML = '<i class="fa fa-check" style="font-size: 12px; color: #10b981;"></i>';
            setTimeout(() => {
                btnElement.innerHTML = originalIcon;
            }, 2000);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(orderNumber).then(copyText).catch(function(err) {
                 console.error('Clipboard API failed: ', err);
                 fallbackCopy(orderNumber, btnElement);
            });
        } else {
            fallbackCopy(orderNumber, btnElement);
        }
    }

    function fallbackCopy(text, btnElement) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Ensure the textarea is not visible and doesn't cause scrolling
        textArea.style.position = "fixed";
        textArea.style.left = "0";
        textArea.style.top = "0";
        textArea.style.opacity = "0";
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            // Show feedback
            const originalIcon = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fa fa-check" style="font-size: 12px; color: #10b981;"></i>';
            setTimeout(() => {
                btnElement.innerHTML = originalIcon;
            }, 2000);
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
        }
        document.body.removeChild(textArea);
    }
</script>
@endsection
