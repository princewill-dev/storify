<!DOCTYPE html>
<html lang="en">
<head>
    <title>POS · {{ $activeStore?->name ?? 'No Store' }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('vendor_files/assets/vendor/@flaticon/flaticon-uicons/css/all/all.css') }}">
    @if($paystackKey)<script src="https://js.paystack.co/v1/inline.js"></script>@endif
    <style>
        .pos-page { display: flex; flex-direction: column; height: 100vh; background: #f8fafc; }
        .pos-header { flex-shrink: 0; padding: 0.5rem 1rem; background: #fff; border-bottom: 1px solid #e2e8f0; }
        .pos-body { flex: 1; display: flex; overflow: hidden; min-height: 0; }
        .pos-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .pos-cart { width: 360px; flex-shrink: 0; display: flex; flex-direction: column; background: #fff; border-left: 1px solid #e2e8f0; }
        .pos-products { flex: 1; overflow-y: auto; padding: 1rem; }
        .pos-cart-items { flex: 1; overflow-y: auto; padding: 1rem; min-height: 0; }
        .pos-cart-footer { flex-shrink: 0; border-top: 1px solid #e2e8f0; padding: 1rem; }
        .tab-btn.active { border-bottom: 2px solid #1e293b; color: #1e293b; }
        .product-card { cursor: pointer; transition: all 0.12s; }
        .product-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .product-card:active { transform: scale(0.97); }
        @media (max-width: 768px) {
            .pos-cart { width: 100%; max-height: 45vh; border-left: none; border-top: 1px solid #e2e8f0; }
            .pos-body { flex-direction: column; }
        }
    </style>
</head>
<body>
@if(session('success'))
<div class="fixed top-4 right-4 z-[100] bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg shadow-lg text-sm" id="posFlash">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="fixed top-4 right-4 z-[100] bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg shadow-lg text-sm" id="posFlash">{{ session('error') }}</div>
@endif
@if($errors->any())
<div class="fixed top-4 right-4 z-[100] bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg shadow-lg text-sm" id="posFlash">{{ $errors->first() }}</div>
@endif
<div class="pos-page">
    <div class="pos-header flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2 min-w-0">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-500 shrink-0"><i class="fi fi-rr-shop text-sm"></i></span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-800 truncate">{{ $activeStore?->name ?? 'No Store' }}</p>
                <p class="text-[11px] text-slate-400 truncate">{{ $user->name }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if($assignedStores->count() > 1)
            <form method="POST" action="{{ route('pos.switch-store') }}" class="flex items-center">
                @csrf
                <select name="store_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 text-xs shadow-sm focus:border-slate-500 focus:ring-slate-500 py-1.5">
                    @foreach($assignedStores as $s)
                    <option value="{{ $s->id }}" {{ ($activeStore && $activeStore->id === $s->id) ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </form>
            @endif
            @if($activeSession)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> {{ $activeSession->session_code }}</span>
            @if($canCloseSession)
            <button onclick="document.getElementById('closeSessionModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">Close</button>
            @endif
            @elseif($canOpenSession)
            <button onclick="document.getElementById('openSessionModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors">Open Session</button>
            @endif
            <form method="POST" action="{{ route('management.auth.logout') }}" class="inline">
                @csrf
                <button class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-slate-500 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">Logout</button>
            </form>
        </div>
    </div>

    <div class="pos-body">
        @if(!$activeStore)
        <div class="flex-1 flex items-center justify-center"><div class="text-center"><p class="text-slate-500">No store assigned.</p></div></div>
        @else
        <div class="pos-main">
            <div class="flex items-center border-b border-slate-200 bg-white px-4">
                <button class="tab-btn active px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700" onclick="switchTab('products')" id="tabProducts">Products</button>
                <button class="tab-btn px-4 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-700" onclick="switchTab('history')" id="tabHistory">Sales History</button>
            </div>

            <div id="tabContentProducts" class="flex-1 flex overflow-hidden min-h-0">
                <div class="pos-products">
                    @if($canProcessSale)
                    <input type="text" id="productSearch" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm mb-3" placeholder="Search products..." autofocus>
                    @endif
                    <div id="productGrid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach($products as $product)
                        @php $img = $product->images->first(); @endphp
                        <div class="product-card bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm" @if($canProcessSale) onclick="addToCart(@js($product->only(['id','name','amount','quantity'])))" @endif>
                            <div class="aspect-[4/3] bg-slate-100 flex items-center justify-center overflow-hidden">
                                @if($img && $img->path)
                                <img src="{{ asset('storage/' . $img->path) }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                                @else
                                <i class="fi fi-rr-cube text-slate-300 text-2xl"></i>
                                @endif
                            </div>
                            <div class="p-2.5">
                                <p class="text-xs font-semibold text-slate-800 truncate">{{ $product->name }}</p>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-sm font-bold text-slate-700">₦{{ number_format($product->amount, 2) }}</span>
                                    <span class="text-[10px] text-slate-400">{{ $product->quantity }} left</span>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                @if($canProcessSale)
                <div class="pos-cart">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-800">Current Sale</h3>
                        <button onclick="clearCart()" class="text-xs text-slate-400 hover:text-red-500">Clear</button>
                    </div>
                    <div id="cartItems" class="pos-cart-items"><div class="text-center text-slate-400 py-6 text-sm">Cart is empty — click a product</div></div>
                    <div class="pos-cart-footer">
                        <div class="flex justify-between text-sm mb-1"><span class="text-slate-500">Subtotal</span><span id="cartSubtotal" class="font-semibold">₦0.00</span></div>
                        <div class="flex justify-between text-lg font-bold mb-4"><span>Total</span><span id="cartTotal">₦0.00</span></div>
                        <button onclick="openCheckout()" id="checkoutBtn" disabled class="w-full py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">Process Sale</button>
                    </div>
                </div>
                @endif
            </div>

            <div id="tabContentHistory" class="hidden flex-1 overflow-y-auto p-4">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-slate-100">
                            <th class="px-4 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase">Order</th>
                            <th class="px-4 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase hidden sm:table-cell">Items</th>
                            <th class="px-4 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase">Total</th>
                            <th class="px-4 py-3 text-center text-[11px] font-semibold text-slate-400 uppercase hidden sm:table-cell">Status</th>
                            <th class="px-4 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase hidden md:table-cell">Time</th>
                            <th class="px-4 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase"></th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($recentOrders as $order)
                            @php $tx = $order->transactions->first(); @endphp
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-4 py-3"><span class="text-xs font-medium text-slate-800">#{{ $order->order_number ?? $order->id }}</span></td>
                                <td class="px-4 py-3 text-center hidden sm:table-cell"><span class="text-xs text-slate-600">{{ $order->items->count() }}</span></td>
                                <td class="px-4 py-3 text-right"><span class="text-xs font-semibold text-slate-800">₦{{ number_format($order->total, 2) }}</span></td>
                                <td class="px-4 py-3 text-center hidden sm:table-cell">
                                    @if($tx)
                                    @if($tx->status === 'confirmed') <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Paid</span>
                                    @elseif($tx->status === 'refund_pending') <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">Refund Pending</span>
                                    @elseif($tx->status === 'refunded') <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-[10px] font-medium text-purple-700">Refunded</span>
                                    @else <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">{{ ucfirst($tx->status->value) }}</span>
                                    @endif
                                    @else <span class="text-[10px] text-slate-400">—</span> @endif
                                </td>
                                <td class="px-4 py-3 text-right hidden md:table-cell"><span class="text-[11px] text-slate-400">{{ $order->created_at->format('h:i A') }}</span></td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($tx && $tx->status === 'confirmed')
                                        <button onclick="openRefundModal(@js($order->only(['id','order_number','total'])))" class="px-2 py-1 text-[10px] font-medium text-amber-600 bg-amber-50 hover:bg-amber-100 rounded">Refund</button>
                                        @endif
                                        <a href="{{ route('pos.receipt', ['store' => $activeStore, 'order' => $order]) }}" class="px-2 py-1 text-[10px] font-medium text-slate-500 bg-slate-100 hover:bg-slate-200 rounded">View Receipt</a>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400">No sales recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

{{-- Open Session Modal --}}
<div id="openSessionModal" class="hidden fixed inset-0 z-50 overflow-y-auto" data-auto-open="{{ $activeSession ? 'false' : 'true' }}">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="if(this.parentElement.parentElement.dataset.autoOpen!=='true'){document.getElementById('openSessionModal').classList.add('hidden')}"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Open POS Session</h3></div>
            <form method="POST" action="{{ route('pos.session.open', ['store' => $activeStore]) }}" class="p-6 space-y-4">
                @csrf
                <div><label class="block text-sm font-medium text-slate-700">Opening Cash Float (in kobo)</label><input type="number" name="opening_balance" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" min="0" placeholder="0" required></div>
                <div class="flex items-center gap-3"><button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Open</button><button type="button" onclick="document.getElementById('openSessionModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50" id="openSessionCancelBtn">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

{{-- Close Session Modal --}}
@if($activeSession)
<div id="closeSessionModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('closeSessionModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Close POS Session</h3></div>
            <form method="POST" action="{{ route('pos.session.close', ['store' => $activeStore]) }}" class="p-6 space-y-4">
                @csrf
                <div class="bg-slate-50 rounded-lg p-3 text-xs space-y-1">
                    <div class="flex justify-between"><span>Opening:</span><span class="font-semibold">₦{{ number_format($activeSession->opening_balance / 100, 2) }}</span></div>
                    <div class="flex justify-between"><span>Expected:</span><span id="expectedClose" class="font-semibold">--</span></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700">Actual Cash Counted (in kobo)</label><input type="number" name="closing_balance_actual" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" min="0" placeholder="0" required></div>
                <div><label class="block text-sm font-medium text-slate-700">Notes</label><textarea name="notes" id="orderNotes" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" rows="2"></textarea></div>
                <div class="flex items-center gap-3"><button type="submit" class="flex-1 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Close Session</button><button type="button" onclick="document.getElementById('closeSessionModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>
@endif

@if($canProcessSale && $activeStore)
{{-- Checkout Modal --}}
<div id="checkoutModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('checkoutModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Complete Sale</h3><button onclick="document.getElementById('checkoutModal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button></div>
            <form id="checkoutForm" method="POST" action="{{ route('pos.checkout', ['store' => $activeStore]) }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="items" id="checkoutItems">
                <input type="hidden" name="paystack_reference" id="paystackRef">
                <div class="text-center py-3 bg-slate-50 rounded-lg"><span class="text-xs text-slate-500">Total</span><p id="modalTotal" class="text-2xl font-bold text-slate-900"></p></div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Payment Method</label>
                    <div class="flex flex-wrap gap-2">
                        <input type="radio" name="payment_method" id="payCash" value="cash" checked class="hidden peer">
                        <label for="payCash" class="flex-1 text-center px-3 py-2 rounded-lg text-sm font-medium border-2 cursor-pointer transition-colors peer-checked:border-slate-900 peer-checked:bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300" onclick="selectPayTab('cash')"><i class="fi fi-rr-money-bill-wave text-xs mr-1"></i> Cash</label>
                        @foreach($paymentMethods as $pm)
                        <input type="radio" name="payment_method" id="pay{{ ucfirst($pm['id']) }}" value="{{ $pm['id'] }}" class="hidden peer">
                        <label for="pay{{ ucfirst($pm['id']) }}" class="flex-1 text-center px-3 py-2 rounded-lg text-sm font-medium border-2 cursor-pointer transition-colors peer-checked:border-slate-900 peer-checked:bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300" onclick="selectPayTab('{{ $pm['id'] }}')"><i class="fi fi-rr-{{ $pm['icon'] }} text-xs mr-1"></i> {{ $pm['label'] }}</label>
                        @endforeach
                    </div>
                </div>
                <div id="cashFields"><label class="block text-sm font-medium text-slate-700">Amount Tendered (₦)</label><input type="number" id="amountTendered" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" min="0" step="0.01" placeholder="0.00"><p class="text-xs mt-1">Change: <span id="changeDue" class="font-bold text-emerald-600">₦0.00</span></p></div>
                <div id="bankFields" class="hidden space-y-2"><label class="block text-sm font-medium text-slate-700">Bank Account</label>@foreach($bankAccounts as $bank)<div class="border rounded-lg p-2.5 bg-slate-50 text-sm"><p class="font-semibold">{{ $bank->bank_name }}</p><p class="text-slate-500 text-xs">{{ $bank->account_number }} — {{ $bank->account_name }}</p></div>@endforeach</div>
                <div id="cardNotice" class="hidden"><div class="rounded-lg bg-blue-50 border border-blue-100 p-3 text-sm text-blue-700"><i class="fi fi-rr-info mr-1"></i> Paystack will open in a popup.</div></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="block text-sm font-medium text-slate-700">Customer Name</label><input type="text" name="customer_name" id="customerName" class="w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Optional"></div><div><label class="block text-sm font-medium text-slate-700">Phone</label><input type="text" name="customer_phone" id="customerPhone" class="w-full rounded-lg border-slate-300 px-3 py-2 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" placeholder="Optional"></div></div>
                <div class="flex items-center gap-3 pt-2"><button type="button" onclick="submitCheckout()" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">Complete Sale</button><button type="button" onclick="document.getElementById('checkoutModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>

{{-- Refund Modal --}}
<div id="refundModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="document.getElementById('refundModal').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="text-base font-semibold text-slate-800">Request Refund</h3></div>
            <form id="refundForm" method="POST" class="p-6 space-y-4">
                @csrf
                <div class="text-center py-2 bg-slate-50 rounded-lg"><span class="text-xs text-slate-500">Order</span><p id="refundOrderNum" class="text-sm font-bold text-slate-800"></p><p id="refundOrderTotal" class="text-xs text-slate-500"></p></div>
                <div><label class="block text-sm font-medium text-slate-700">Reason <span class="text-red-500">*</span></label><textarea name="reason" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm" rows="3" required placeholder="Why is this being refunded?"></textarea></div>
                <p class="text-xs text-slate-400">Refund will be pending until an admin reviews and approves it.</p>
                <div class="flex items-center gap-3"><button type="submit" class="flex-1 py-2.5 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700">Request Refund</button><button type="button" onclick="document.getElementById('refundModal').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button></div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Receipt Modal --}}
<div id="receiptModal" class="hidden fixed inset-0 z-[60] overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50"></div>
        <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl overflow-hidden">
            {{-- Success Banner --}}
            <div class="bg-emerald-500 px-6 py-5 text-center text-white">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-white/20 mb-2">
                    <i class="fi fi-rr-check text-2xl"></i>
                </span>
                <h3 class="text-lg font-bold">Sale Complete!</h3>
                <p class="text-xs text-emerald-100 mt-0.5">Transaction recorded successfully</p>
            </div>
            <div id="receiptContent" class="p-6"></div>
            <div class="px-6 py-4 border-t border-slate-100 flex items-center gap-3">
                <button onclick="printReceipt()" class="flex-1 py-2.5 border border-slate-200 text-sm font-medium rounded-lg hover:bg-slate-50"><i class="fi fi-rr-print mr-1.5"></i> Print</button>
                <button onclick="newSale()" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800">New Sale</button>
            </div>
        </div>
    </div>
</div>

@if($canProcessSale && $activeStore)
<script>setTimeout(()=>{const e=document.getElementById('posFlash');if(e)e.remove();},4000);</script>
<script>
let cart = [];
const csrf = '{{ csrf_token() }}';

function addToCart(product) {
    let ex = cart.find(i => i.product_id === product.id);
    if (ex) { ex.quantity += 1; } else { cart.push({ product_id: product.id, name: product.name, price: parseFloat(product.amount), quantity: 1 }); }
    renderCart();
}
function removeFromCart(i) { cart.splice(i, 1); renderCart(); }
function clearCart() { cart = []; renderCart(); }
function updateQty(i, d) { cart[i].quantity += d; if (cart[i].quantity <= 0) cart.splice(i, 1); renderCart(); }
function renderCart() {
    let h = '', t = 0;
    cart.forEach((item, i) => {
        let it = item.price * item.quantity; t += it;
        h += `<div class="flex items-center justify-between py-2 border-b border-slate-50"><div class="flex-1 min-w-0"><p class="text-sm font-medium text-slate-800 truncate">${item.name}</p><div class="flex items-center gap-2 mt-1"><button class="w-5 h-5 rounded bg-slate-100 text-slate-500 text-xs flex items-center justify-center" onclick="updateQty(${i},-1)">−</button><span class="text-xs w-5 text-center">${item.quantity}</span><button class="w-5 h-5 rounded bg-slate-100 text-slate-500 text-xs flex items-center justify-center" onclick="updateQty(${i},1)">+</button></div></div><div class="text-right shrink-0 ml-3"><p class="text-sm font-semibold">₦${it.toFixed(2)}</p><button class="text-[10px] text-slate-400 hover:text-red-500 mt-0.5" onclick="removeFromCart(${i})">Remove</button></div></div>`;
    });
    document.getElementById('cartItems').innerHTML = h || '<div class="text-center text-slate-400 py-6 text-sm">Cart is empty — click a product</div>';
    document.getElementById('cartSubtotal').textContent = '₦' + t.toFixed(2);
    document.getElementById('cartTotal').textContent = '₦' + t.toFixed(2);
    document.getElementById('checkoutBtn').disabled = cart.length === 0;
}
function openCheckout() {
    const t = cart.reduce((s,i) => s + i.price * i.quantity, 0);
    document.getElementById('modalTotal').textContent = '₦' + t.toFixed(2);
    document.getElementById('amountTendered').value = '';
    document.getElementById('changeDue').textContent = '₦0.00';
    document.getElementById('payCash').checked = true;
    selectPayTab('cash');
    document.getElementById('checkoutModal').classList.remove('hidden');
}
function selectPayTab(m) {
    document.querySelectorAll('#checkoutModal label[for^="pay"]').forEach(l => { l.classList.remove('border-slate-900','bg-slate-50'); l.classList.add('border-slate-200'); });
    const lb = document.querySelector(`label[for="pay${m.charAt(0).toUpperCase()+m.slice(1)}"]`);
    if (lb) { lb.classList.add('border-slate-900','bg-slate-50'); lb.classList.remove('border-slate-200'); }
    document.getElementById('cashFields').classList.toggle('hidden', m !== 'cash');
    document.getElementById('bankFields').classList.toggle('hidden', m !== 'transfer');
    document.getElementById('cardNotice').classList.toggle('hidden', m !== 'card');
}
document.getElementById('amountTendered')?.addEventListener('input', function() {
    const t = cart.reduce((s,i) => s + i.price * i.quantity, 0);
    document.getElementById('changeDue').textContent = '₦' + Math.max(0, (parseFloat(this.value)||0) - t).toFixed(2);
});
function submitCheckout() {
    const m = document.querySelector('input[name="payment_method"]:checked')?.value;
    const t = cart.reduce((s,i) => s + i.price * i.quantity, 0);
    @if($paystackKey)
    if (m === 'paystack') {
        PaystackPop.setup({ key:'{{ $paystackKey }}', email:'{{ $user->email }}', amount:Math.round(t*100), currency:'NGN', ref:'POS-'+Date.now(), metadata:{store_id:'{{ $activeStore->id }}'}, onClose(){}, callback(r){ document.getElementById('paystackRef').value = r.reference; doCheckout(); }}).openIframe();
        return;
    }
    @endif
    doCheckout();
}

async function doCheckout() {
    const btn = document.querySelector('#checkoutModal button[onclick="submitCheckout()"]');
    btn.disabled = true; btn.textContent = 'Processing...';

    const body = new FormData();
    body.append('_token', document.querySelector('meta[name="csrf-token"]').content);
    body.append('items', JSON.stringify(cart));
    body.append('payment_method', document.querySelector('input[name="payment_method"]:checked')?.value || 'cash');
    body.append('amount_tendered', document.getElementById('amountTendered')?.value || cart.reduce((s,i) => s + i.price * i.quantity, 0));
    body.append('paystack_reference', document.getElementById('paystackRef')?.value || '');
    body.append('customer_name', document.getElementById('customerName')?.value || '');
    body.append('customer_phone', document.getElementById('customerPhone')?.value || '');
    body.append('notes', document.getElementById('orderNotes')?.value || '');

    try {
        const resp = await fetch('{{ route('pos.checkout', ['store' => $activeStore]) }}', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('checkoutModal').classList.add('hidden');
            showReceiptModal(data.receipt);
            clearCart();
        } else {
            alert(data.message || 'Checkout failed.');
        }
    } catch (e) {
        console.error(e);
        alert('Checkout failed. Please try again.');
    } finally {
        btn.disabled = false; btn.textContent = 'Complete Sale';
    }
}

function showReceiptModal(receipt) {
    const itemsHtml = receipt.items.map(i =>
        `<div class="flex justify-between text-sm"><span>${i.name} × ${i.qty}</span><span class="font-medium">₦${i.subtotal.toFixed(2)}</span></div>`
    ).join('');
    document.getElementById('receiptContent').innerHTML = `
        <div class="text-center mb-4">
            <h3 class="text-lg font-bold text-slate-800">${receipt.store_name}</h3>
            ${receipt.store_address ? `<p class="text-xs text-slate-400">${receipt.store_address}</p>` : ''}
        </div>
        <div class="border-t border-b border-dashed border-slate-200 py-3 space-y-2 mb-3">
            <div class="flex justify-between text-xs text-slate-500"><span>Order</span><span class="font-mono font-medium text-slate-700">#${receipt.order_number}</span></div>
            <div class="flex justify-between text-xs text-slate-500"><span>Date</span><span>${receipt.date}</span></div>
            <div class="flex justify-between text-xs text-slate-500"><span>Cashier</span><span>${receipt.cashier}</span></div>
            <div class="flex justify-between text-xs text-slate-500"><span>Payment</span><span class="capitalize">${receipt.payment_method}</span></div>
            ${receipt.customer_name ? `<div class="flex justify-between text-xs text-slate-500"><span>Customer</span><span>${receipt.customer_name}</span></div>` : ''}
        </div>
        <div class="space-y-1.5 mb-3">${itemsHtml}</div>
        <div class="border-t border-slate-200 pt-3 space-y-1">
            <div class="flex justify-between text-sm font-semibold"><span>Total</span><span>₦${receipt.items.reduce((s,i) => s + i.subtotal, 0).toFixed(2)}</span></div>
            ${receipt.amount_tendered > 0 ? `<div class="flex justify-between text-xs text-slate-500"><span>Amount Tendered</span><span>₦${receipt.amount_tendered.toFixed(2)}</span></div>` : ''}
            ${receipt.change > 0 ? `<div class="flex justify-between text-sm font-semibold text-emerald-600"><span>Change</span><span>₦${receipt.change.toFixed(2)}</span></div>` : ''}
        </div>
    `;
    document.getElementById('receiptModal').classList.remove('hidden');
}

function newSale() {
    document.getElementById('receiptModal').classList.add('hidden');
    cart = [];
    renderCart();
}

function printReceipt() {
    const content = document.getElementById('receiptContent').innerHTML;
    const win = window.open('', '_blank', 'width=380,height=600');
    win.document.write(`<html><head><title>Receipt</title><script src="https://cdn.tailwindcss.com"><\/script><style>body{font-family:system-ui,-apple-system,sans-serif;padding:1.5rem;}@media print{body{padding:0.5rem;}}<\/style></head><body>${content}</body></html>`);
    win.document.close();
    setTimeout(() => win.print(), 300);
}
function switchTab(t) {
    document.getElementById('tabContentProducts').classList.toggle('hidden', t !== 'products');
    document.getElementById('tabContentProducts').classList.toggle('flex', t === 'products');
    document.getElementById('tabContentHistory').classList.toggle('hidden', t !== 'history');
    document.getElementById('tabProducts').classList.toggle('active', t === 'products');
    document.getElementById('tabHistory').classList.toggle('active', t === 'history');
}
function openRefundModal(order) {
    document.getElementById('refundForm').action = '/pos/{{ $activeStore->store_id }}/refund/' + order.id;
    document.getElementById('refundOrderNum').textContent = '#' + (order.order_number || order.id);
    document.getElementById('refundOrderTotal').textContent = '₦' + parseFloat(order.total).toFixed(2);
    document.getElementById('refundModal').classList.remove('hidden');
}
document.getElementById('productSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase(); let v = 0;
    document.querySelectorAll('#productGrid .product-card').forEach(c => { const m = c.querySelector('p').textContent.toLowerCase().includes(q); c.style.display = m?'':'none'; if(m) v++; });
});
</script>
@endif
{{-- Auto-show open session modal if no active session --}}
@if(!$activeSession && $canOpenSession)
<script>document.getElementById('openSessionModal').classList.remove('hidden');</script>
@endif
</body>
</html>
