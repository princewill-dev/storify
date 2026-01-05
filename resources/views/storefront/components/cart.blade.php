<!-- cart mini area start -->
<div class="cartmini__area">
    <div class="cartmini__wrapper">
    <div class="cartmini__title">
        <h4>Shopping cart</h4>
    </div>
    <div class="cartmini__close">
        <button type="button" class="cartmini__close-btn"><i class="fal fa-times"></i></button>
    </div>
    <div class="cartmini__widget">
        <div class="cartmini__inner">
            <ul id="cartMiniList">
                <!-- Items will be injected here via JS -->
            </ul>
        </div>
        <div class="cartmini__checkout">
            <div class="cartmini__checkout-title mb-30">
                <h4>Subtotal:</h4>
                <span id="cartMiniSubtotal">0.00</span>
            </div>
            <div class="cartmini__checkout-btn">
                <a href="{{ route('home.store.cart', ['store_subdomain' => $store->slug ?? 'store']) }}" class="m-btn m-btn-border mb-10 w-100"> <span></span> view cart</a>
            </div>
        </div>
    </div>
    </div>
</div>
<div class="body-overlay"></div>
<!-- cart mini area end -->