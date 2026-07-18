<!-- cart slide-out -->
<div class="cart-slideout">
    <div class="cart-slideout__overlay cart-toggle-btn"></div>
    <div class="cart-slideout__panel">
        <div class="cart-slideout__header">
            <h4>Your Cart</h4>
            <button class="cart-slideout__close cart-toggle-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="cart-slideout__body">
            <ul id="cartMiniList"></ul>
        </div>
        
        <div class="cart-slideout__footer">
            <div class="cart-slideout__subtotal">
                <span>Subtotal</span>
                <span id="cartMiniSubtotal">₦0.00</span>
            </div>
            <a href="{{ route('home.store.cart', ['store_subdomain' => $store->slug ?? 'store']) }}" class="cart-slideout__checkout-btn">Checkout</a>
        </div>
    </div>
</div>
<div class="body-overlay"></div>

<style>
.cart-slideout { position:fixed; top:0; right:0; bottom:0; left:0; z-index:9999; pointer-events:none; }
.cart-slideout.active { pointer-events:auto; }
.cart-slideout__overlay { position:absolute; inset:0; background:rgba(0,0,0,0.35); opacity:0; transition:opacity 0.25s; }
.cart-slideout.active .cart-slideout__overlay { opacity:1; }
.cart-slideout__panel { position:absolute; top:0; right:0; bottom:0; width:100%; max-width:400px; background:#fff; display:flex; flex-direction:column; transform:translateX(100%); transition:transform 0.3s cubic-bezier(0.4,0,0.2,1); box-shadow:-4px 0 24px rgba(0,0,0,0.08); }
.cart-slideout.active .cart-slideout__panel { transform:translateX(0); }
.cart-slideout__header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #f1f5f9; flex-shrink:0; }
.cart-slideout__header h4 { font-size:15px; font-weight:700; color:#0f172a; margin:0; letter-spacing:-0.01em; }
.cart-slideout__close { border:none; background:transparent; color:#94a3b8; cursor:pointer; padding:4px; border-radius:8px; display:flex; align-items:center; transition:all 0.15s; }
.cart-slideout__close:hover { color:#0f172a; background:#f1f5f9; }
.cart-slideout__body { flex:1; overflow-y:auto; padding:16px 24px; }
.cart-slideout__body ul { list-style:none; padding:0; margin:0; }
.cart-slideout__body li { display:flex; gap:14px; padding:16px 0; border-bottom:1px solid #f1f5f9; position:relative; }
.cart-slideout__body li:last-child { border-bottom:none; }
.cart-slideout__thumb { width:64px; height:64px; border-radius:10px; overflow:hidden; background:#f8fafc; flex-shrink:0; }
.cart-slideout__thumb img { width:100%; height:100%; object-fit:cover; }
.cart-slideout__thumb video { width:100%; height:100%; object-fit:cover; }
.cart-slideout__info { flex:1; min-width:0; }
.cart-slideout__info h5 { font-size:13px; font-weight:600; color:#0f172a; margin:0 0 6px; line-height:1.3; padding-right:22px; }
.cart-slideout__info h5 a { color:inherit; text-decoration:none; }
.cart-slideout__price { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:8px; }
.cart-slideout__qty { display:inline-flex; align-items:center; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
.cart-slideout__qty button { width:28px; height:28px; border:none; background:transparent; color:#64748b; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; }
.cart-slideout__qty button:hover { background:#f1f5f9; color:#0f172a; }
.cart-slideout__qty input { width:32px; height:28px; border:none; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; font-size:12px; font-weight:600; color:#0f172a; padding:0; }
.cart-slideout__remove { position:absolute; top:16px; right:0; border:none; background:transparent; color:#cbd5e1; cursor:pointer; padding:2px; border-radius:6px; display:flex; align-items:center; transition:all 0.15s; }
.cart-slideout__remove:hover { color:#ef4444; background:#fef2f2; }
.cart-slideout__empty { text-align:center; padding:40px 20px; color:#94a3b8; font-size:14px; }
.cart-slideout__footer { padding:20px 24px; border-top:1px solid #f1f5f9; background:#fafafa; flex-shrink:0; }
.cart-slideout__subtotal { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.cart-slideout__subtotal span:first-child { font-size:13px; color:#64748b; font-weight:500; }
.cart-slideout__subtotal span:last-child { font-size:18px; color:#0f172a; font-weight:700; }
.cart-slideout__checkout-btn { display:flex; align-items:center; justify-content:center; width:100%; padding:14px; background:#0f172a; color:#fff; border:none; border-radius:12px; font-size:14px; font-weight:600; text-decoration:none; transition:background 0.15s; letter-spacing:-0.01em; }
.cart-slideout__checkout-btn:hover { background:#1e293b; color:#fff; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cart = document.querySelector('.cart-slideout');
    const toggles = document.querySelectorAll('.cart-toggle-btn');
    toggles.forEach(function(el) {
        el.addEventListener('click', function() { cart.classList.toggle('active'); });
    });
});
</script>
