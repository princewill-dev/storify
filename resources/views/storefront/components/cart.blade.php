<!-- cart mini area start -->
<div class="cartmini__area custom-cartmini-design">
    <div class="cartmini__wrapper">
        <div class="cartmini__header">
            <div class="cartmini__title">
                <h4>SHOPPING CART</h4>
            </div>
            <div class="cartmini__close">
                <button type="button" class="cartmini__close-btn"><i class="fal fa-times"></i></button>
            </div>
        </div>
        
        <div class="cartmini__widget">
            <div class="cartmini__inner">
                <ul id="cartMiniList">
                    <!-- Items will be injected here via JS -->
                </ul>
            </div>
            <div class="cartmini__checkout">
                <div class="cartmini__checkout-title">
                    <h4>Subtotal:</h4>
                    <span id="cartMiniSubtotal">0.00</span>
                </div>
                <div class="cartmini__checkout-btn">
                    <a href="{{ route('home.store.cart', ['store_subdomain' => $store->slug ?? 'store']) }}" class="custom-view-cart-btn">Checkout</a>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="body-overlay"></div>

<style>
/* Custom overrides for the sleek cart design */
.custom-cartmini-design .cartmini__wrapper {
    background: #ffffff;
    display: flex;
    flex-direction: column;
}
.custom-cartmini-design .cartmini__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 25px 30px;
    border-bottom: 1px solid #f0f0f0;
}
.custom-cartmini-design .cartmini__title h4 {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: #1a1a1a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.custom-cartmini-design .cartmini__close-btn {
    background: transparent;
    border: none;
    color: #1a1a1a;
    font-size: 20px;
}
.custom-cartmini-design .cartmini__close-btn:hover {
    color: #5b21b6;
    background: transparent;
}
.custom-cartmini-design .cartmini__checkout {
    padding: 30px;
    background: #fff;
    border-top: 1px solid #f0f0f0;
}
.custom-cartmini-design .cartmini__checkout-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}
.custom-cartmini-design .cartmini__checkout-title h4 {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a1a;
    margin: 0;
    text-transform: none;
}
.custom-cartmini-design .cartmini__checkout-title span {
    font-size: 22px;
    font-weight: 700;
    color: #5b21b6;
}
.custom-view-cart-btn {
    display: block;
    width: 100%;
    text-align: center;
    padding: 15px 0;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 700;
    color: #5b21b6;
    background: transparent;
    transition: all 0.3s ease;
    text-decoration: none;
}
.custom-view-cart-btn:hover {
    border-color: #5b21b6;
    color: #5b21b6;
    background: rgba(91, 33, 182, 0.05);
}

/* Custom styles for dynamically injected JS cart items */
.custom-cartmini-design #cartMiniList {
    padding: 30px 30px 0;
}
.custom-cartmini-design #cartMiniList li {
    display: flex;
    margin-bottom: 25px;
    padding-bottom: 25px;
    border-bottom: 1px solid #f0f0f0;
    position: relative;
}
.custom-cartmini-design #cartMiniList li:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0px;
}
.custom-cartmini-design .cartmini__thumb {
    width: 80px;
    flex-shrink: 0;
    margin-right: 20px;
}
.custom-cartmini-design .cartmini__thumb img {
    width: 100%;
    border-radius: 4px;
}
.custom-cartmini-design .cartmini__content {
    flex-grow: 1;
}
.custom-cartmini-design .cartmini__content h5 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 10px;
    color: #1a1a1a;
}
.custom-cartmini-design .cartmini__content h5 a {
    color: #1a1a1a;
}
.custom-cartmini-design .cartmini__del {
    position: absolute;
    top: 0;
    right: 0;
    color: #999;
    font-size: 14px;
}
.custom-cartmini-design .cartmini__del:hover {
    color: #ef4444;
    background: transparent;
}
.custom-qty-wrapper {
    display: flex;
    align-items: center;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    margin-bottom: 15px;
}
.custom-qty-btn {
    width: 30px;
    height: 30px;
    display: flex;
    justify-content: center;
    align-items: center;
    background: transparent;
    border: none;
    color: #475569;
    font-size: 16px;
    cursor: pointer;
}
.custom-qty-input {
    width: 40px;
    height: 30px;
    border: none;
    border-left: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
    text-align: center;
    font-size: 14px;
    font-weight: 500;
    color: #1a1a1a;
    padding: 0;
}
.custom-cartmini-design .product__sm-price {
    font-size: 16px;
    font-weight: 600;
    color: #5b21b6;
}
</style>
<!-- cart mini area end -->