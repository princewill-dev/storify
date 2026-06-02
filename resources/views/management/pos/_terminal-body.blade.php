<div class="pos-layout rounded-xl shadow-sm border border-slate-200 overflow-hidden bg-white">
    <div class="pos-products-panel">
        <div class="mb-4">
            <input type="text" id="productSearch" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Search products..." autofocus>
        </div>
        <div class="mb-3 flex items-center justify-between">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Products</span>
            <span class="text-xs text-slate-400">{{ $products->count() }} items</span>
        </div>
        <div id="productGrid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach($products as $product)
            <div class="product-card bg-white rounded-xl border border-slate-200 p-3 shadow-sm"
                 onclick="addToCart(@js($product->only(['id','name','amount','quantity'])))">
                <p class="text-sm font-semibold text-slate-800 truncate">{{ $product->name }}</p>
                <div class="flex items-center justify-between mt-1.5">
                    <span class="text-sm font-bold text-slate-700">₦{{ number_format($product->amount, 2) }}</span>
                    <span class="text-[11px] text-slate-400">Qty: {{ $product->quantity }}</span>
                </div>
            </div>
            @endforeach
        </div>
        <div id="noResults" class="hidden text-center py-8">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-search"></i></span>
            <p class="text-sm text-slate-500">No products match your search</p>
        </div>
    </div>

    <div class="pos-cart-panel">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Current Sale</h3>
            <button onclick="clearCart()" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Clear</button>
        </div>
        <div id="cartItems" class="cart-items-scroll">
            <div class="text-center text-slate-400 py-8">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-shopping-cart"></i></span>
                <p class="text-sm">Cart is empty</p>
                <p class="text-xs mt-0.5">Click a product to add it</p>
            </div>
        </div>
        <div class="cart-footer-section">
            <div class="flex justify-between text-sm mb-1">
                <span class="text-slate-500">Subtotal</span>
                <span id="cartSubtotal" class="font-semibold text-slate-700">₦0.00</span>
            </div>
            <div class="flex justify-between text-lg font-bold mb-4">
                <span>Total</span>
                <span id="cartTotal" class="text-slate-900">₦0.00</span>
            </div>
            <button onclick="openCheckout()" id="checkoutBtn" disabled
                    class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                Process Sale
            </button>
        </div>
    </div>
</div>

{{-- Checkout Modal --}}
<div id="checkoutModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('checkoutModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-800">Complete Sale</h3>
                <button onclick="document.getElementById('checkoutModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form id="checkoutForm" method="POST" action="{{ route('management.pos.checkout', $store) }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="items" id="checkoutItems">
                <div class="text-center py-3 bg-slate-50 rounded-lg">
                    <span class="text-xs text-slate-500">Total</span>
                    <p id="modalTotal" class="text-2xl font-bold text-slate-900"></p>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Payment Method</label>
                    <div class="flex flex-wrap gap-2">
                        <input type="radio" name="payment_method" id="payCash" value="cash" checked class="hidden peer">
                        <label for="payCash" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium border-2 cursor-pointer transition-colors peer-checked:border-slate-900 peer-checked:bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300" onclick="selectPaymentTab('cash')"><i class="fi fi-rr-money-bill-wave text-xs"></i> Cash</label>
                        @foreach($paymentMethods as $pm)
                        <input type="radio" name="payment_method" id="pay{{ ucfirst($pm['id']) }}" value="{{ $pm['id'] }}" class="hidden peer">
                        <label for="pay{{ ucfirst($pm['id']) }}" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium border-2 cursor-pointer transition-colors peer-checked:border-slate-900 peer-checked:bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300" onclick="selectPaymentTab('{{ $pm['id'] }}')"><i class="fi fi-rr-{{ $pm['icon'] }} text-xs"></i> {{ $pm['label'] }}</label>
                        @endforeach
                    </div>
                </div>
                <div id="cashFields" class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Amount Tendered (₦)</label>
                    <input type="number" name="amount_tendered" id="amountTendered" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" min="0" step="0.01" placeholder="0.00">
                    <p class="text-xs">Change: <span id="changeDue" class="font-bold text-emerald-600">₦0.00</span></p>
                </div>
                <div id="bankFields" class="hidden space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Bank Account for Transfer</label>
                    @foreach($bankAccounts as $bank)
                    <div class="border rounded-lg p-2.5 bg-slate-50 text-sm"><p class="font-semibold text-slate-800">{{ $bank->bank_name }}</p><p class="text-slate-500">{{ $bank->account_number }} — {{ $bank->account_name }}</p></div>
                    @endforeach
                </div>
                <div id="cardNotice" class="hidden">
                    <div class="rounded-lg bg-blue-50 border border-blue-100 p-3 text-sm text-blue-700"><i class="fi fi-rr-info mr-1"></i> Paystack payment will open in a popup.</div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5"><label class="block text-sm font-medium text-slate-700">Customer Name</label><input type="text" name="customer_name" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Optional"></div>
                    <div class="space-y-1.5"><label class="block text-sm font-medium text-slate-700">Phone</label><input type="text" name="customer_phone" class="block w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Optional"></div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="submitCheckout()" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Complete Sale</button>
                    <button type="button" onclick="document.getElementById('checkoutModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
@if($paystackKey)
<script src="https://js.paystack.co/v1/inline.js"></script>
@endif
<script>
let cart = [];

function addToCart(product) {
    let existing = cart.find(i => i.product_id === product.id);
    if (existing) { existing.quantity += 1; }
    else { cart.push({ product_id: product.id, name: product.name, price: parseFloat(product.amount), quantity: 1 }); }
    renderCart();
}
function removeFromCart(index) { cart.splice(index, 1); renderCart(); }
function clearCart() { cart = []; renderCart(); }
function updateQty(index, delta) { cart[index].quantity += delta; if (cart[index].quantity <= 0) cart.splice(index, 1); renderCart(); }
function renderCart() {
    let html = '', total = 0;
    cart.forEach((item, i) => {
        let it = item.price * item.quantity; total += it;
        html += `<div class="flex items-center justify-between py-2 border-b border-slate-50">
            <div class="flex-1 min-w-0"><p class="text-sm font-medium text-slate-800 truncate">${item.name}</p>
                <div class="flex items-center gap-2 mt-1">
                    <button class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="updateQty(${i},-1)">−</button>
                    <span class="text-xs font-medium text-slate-600 w-5 text-center">${item.quantity}</span>
                    <button class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-500 text-xs flex items-center justify-center" onclick="updateQty(${i},1)">+</button>
                </div></div>
            <div class="text-right shrink-0 ml-3"><p class="text-sm font-semibold text-slate-800">₦${it.toFixed(2)}</p><button class="text-[10px] text-slate-400 hover:text-red-500 mt-0.5" onclick="removeFromCart(${i})">Remove</button></div></div>`;
    });
    document.getElementById('cartItems').innerHTML = html || `<div class="text-center text-slate-400 py-8"><span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-slate-100 text-slate-400 mb-2"><i class="fi fi-rr-shopping-cart"></i></span><p class="text-sm">Cart is empty</p><p class="text-xs mt-0.5">Click a product to add it</p></div>`;
    document.getElementById('cartSubtotal').textContent = '₦' + total.toFixed(2);
    document.getElementById('cartTotal').textContent = '₦' + total.toFixed(2);
    document.getElementById('checkoutBtn').disabled = cart.length === 0;
}
function openCheckout() {
    const total = cart.reduce((s,i) => s + i.price * i.quantity, 0);
    document.getElementById('modalTotal').textContent = '₦' + total.toFixed(2);
    document.getElementById('amountTendered').value = '';
    document.getElementById('changeDue').textContent = '₦0.00';
    document.getElementById('payCash').checked = true;
    togglePaymentFields('cash');
    document.getElementById('checkoutModal').classList.remove('hidden');
}
function selectPaymentTab(method) {
    document.querySelectorAll('#checkoutModal label[for^="pay"]').forEach(l => { l.classList.remove('border-slate-900','bg-slate-50'); l.classList.add('border-slate-200'); });
    const label = document.querySelector(`label[for="pay${method.charAt(0).toUpperCase()+method.slice(1)}"]`);
    if (label) { label.classList.add('border-slate-900','bg-slate-50'); label.classList.remove('border-slate-200'); }
    togglePaymentFields(method);
}
function togglePaymentFields(method) {
    document.getElementById('cashFields').classList.toggle('hidden', method !== 'cash');
    document.getElementById('bankFields').classList.toggle('hidden', method !== 'transfer');
    document.getElementById('cardNotice').classList.toggle('hidden', method !== 'card');
}
document.getElementById('amountTendered')?.addEventListener('input', function() {
    const total = cart.reduce((s,i) => s + i.price * i.quantity, 0);
    document.getElementById('changeDue').textContent = '₦' + Math.max(0, (parseFloat(this.value)||0) - total).toFixed(2);
});
function submitCheckout() {
    const form = document.getElementById('checkoutForm');
    document.getElementById('checkoutItems').value = JSON.stringify(cart);
    const method = document.querySelector('input[name="payment_method"]:checked')?.value;
    @if($paystackKey)
    if (method === 'card') {
        const total = cart.reduce((s,i) => s + i.price * i.quantity, 0);
        const handler = PaystackPop.setup({ key:'{{ $paystackKey }}', email:'{{ $user->email }}', amount:Math.round(total*100), currency:'NGN', ref:'POS-'+Date.now(), metadata:{store_id:'{{ $store->id }}'}, onClose(){}, callback(r){ const i=document.createElement('input'); i.type='hidden'; i.name='paystack_reference'; i.value=r.reference; form.appendChild(i); form.submit(); }});
        handler.openIframe(); return;
    }
    @endif
    form.submit();
}
document.getElementById('productSearch').addEventListener('input', function(){
    const q = this.value.toLowerCase(); let visible = 0;
    document.querySelectorAll('#productGrid .product-card').forEach(card => { const m = card.querySelector('p').textContent.toLowerCase().includes(q); card.style.display = m?'':'none'; if(m)visible++; });
    document.getElementById('noResults').classList.toggle('hidden', visible>0);
    document.getElementById('productGrid').classList.toggle('hidden', visible===0 && q.length>0);
});
</script>
@endpush
