<!DOCTYPE html>
<html lang="en">
<head>
    <title>Staff POS - {{ $activeStore?->name ?? 'No Store' }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; }
        .pos-container { height: 100vh; display: flex; flex-direction: column; }
        .pos-header { background: #fff; border-bottom: 1px solid #dee2e6; padding: 10px 20px; }
        .pos-body { flex: 1; display: flex; overflow: hidden; }
        .pos-products { flex: 1; padding: 15px; overflow-y: auto; }
        .pos-cart { width: 380px; background: #fff; border-left: 1px solid #dee2e6; display: flex; flex-direction: column; }
        .cart-items { flex: 1; overflow-y: auto; padding: 15px; }
        .cart-footer { border-top: 1px solid #dee2e6; padding: 15px; }
        .product-card { cursor: pointer; transition: transform 0.1s; }
        .product-card:hover { transform: translateY(-2px); }
        .search-box { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px; max-height: 300px; overflow-y: auto; z-index: 1000; display: none; }
        .cart-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f3f5; }
        .modal-pos .modal-dialog { max-width: 500px; }
    </style>
</head>
<body>
<div class="pos-container">
    <div class="pos-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0">{{ $activeStore?->name ?? 'No Store' }}</h5>
            <small class="text-muted">{{ $user->name }}</small>
        </div>
        <div class="d-flex align-items-center gap-3">
            @if($assignedStores->count() > 1)
            <form method="POST" action="{{ route('staff.pos.switch-store') }}" class="d-flex align-items-center gap-2">
                @csrf
                <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                    @foreach($assignedStores as $s)
                        <option value="{{ $s->id }}" {{ ($activeStore && $activeStore->id === $s->id) ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </form>
            @endif

            @if($activeSession)
                <span class="badge bg-success">Session #{{ $activeSession->session_code }}</span>
                @if($canCloseSession)
                <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#closeSessionModal">Close Session</button>
                @endif
            @else
                @if($canOpenSession)
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#openSessionModal">Open Session</button>
                @endif
            @endif

            <form method="POST" action="{{ route('management.auth.logout') }}" class="d-inline">
                @csrf
                <button class="btn btn-outline-secondary btn-sm">Logout</button>
            </form>
        </div>
    </div>

    <div class="pos-body">
        @if(!$activeStore)
        <div class="flex-fill d-flex align-items-center justify-content-center">
            <div class="text-center">
                <h3>No Store Assigned</h3>
                <p class="text-muted">You are not assigned to any store with POS enabled. Contact your administrator.</p>
            </div>
        </div>
        @else
        <div class="pos-products">
            @if($canProcessSale)
            <div class="search-box mb-3">
                <input type="text" id="productSearch" class="form-control form-control-lg" placeholder="Search products by name or code..." autofocus>
                <div id="searchResults" class="search-results"></div>
            </div>
            @endif

            <div id="productGrid" class="row g-3">
                @foreach($products as $product)
                <div class="col-md-3 col-sm-4 col-6">
                    <div class="product-card card h-100 shadow-sm border-0" @if($canProcessSale) onclick="addToCart('{{ $product->id }}', '{{ addslashes($product->name) }}', {{ $product->amount }}, 1)" @endif>
                        <div class="card-body text-center p-3">
                            <h6 class="card-title mb-1" style="font-size: 0.9rem;">{{ $product->name }}</h6>
                            <p class="text-primary fw-bold mb-0">₦{{ number_format($product->amount, 2) }}</p>
                            <small class="text-muted">Qty: {{ $product->quantity }}</small>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        @if($canProcessSale)
        <div class="pos-cart">
            <div class="cart-header p-3 border-bottom">
                <h6 class="mb-0">Current Sale</h6>
            </div>
            <div id="cartItems" class="cart-items">
                <div class="text-center text-muted py-4">
                    <p class="mb-1">Cart is empty</p>
                    <small>Search or click a product to add it</small>
                </div>
            </div>
            <div class="cart-footer">
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal:</span>
                    <span id="cartSubtotal" class="fw-semibold">₦0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="fw-bold fs-5">Total:</span>
                    <span id="cartTotal" class="fw-bold fs-5 text-primary">₦0.00</span>
                </div>
                <button class="btn btn-success w-100 py-2" onclick="showCheckoutModal()" id="checkoutBtn" disabled>
                    Process Sale
                </button>
            </div>
        </div>
        @else
        <div class="pos-cart d-flex align-items-center justify-content-center">
            <div class="text-center p-4">
                <p class="text-muted mb-0">View-only mode</p>
                <small class="text-muted">You do not have permission to process sales.</small>
            </div>
        </div>
        @endif
        @endif
    </div>
    </div>
@if($activeSession)
<!-- Close Session Modal -->
<div class="modal fade" id="closeSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('staff.pos.session.close', ['store' => $activeStore]) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Close POS Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <small>Opening Balance: ₦{{ number_format($activeSession->opening_balance / 100, 2) }}</small><br>
                        <small>Expected Closing: ₦<span id="expectedClosing">--</span></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Cash Counted (in kobo)</label>
                        <input type="number" name="closing_balance_actual" class="form-control form-control-lg" required min="0" placeholder="Enter cash amount in kobo">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Close Session</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@if($activeStore)
<!-- Open Session Modal -->
<div class="modal fade" id="openSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('staff.pos.session.open', ['store' => $activeStore]) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Open POS Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Opening Cash Float (in kobo)</label>
                        <input type="number" name="opening_balance" class="form-control form-control-lg" required min="0" placeholder="Enter starting cash amount in kobo">
                        <small class="text-muted">Enter the initial cash in the register in kobo (e.g. 500000 = ₦5,000)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Open Session</button>
                </div>
            </form>
        </div>
    </div>
    </div>
@endif

{{-- Modals and JS only needed for users who can process sales --}}
@if($canProcessSale)
@if($activeStore)
<!-- Checkout Modal -->
<div class="modal fade modal-pos" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="checkoutForm" method="POST" action="{{ route('staff.pos.checkout', ['store' => $activeStore]) }}">
                @csrf
                <input type="hidden" name="items" id="checkoutItems">
                <div class="modal-header">
                    <h5 class="modal-title">Complete Sale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 text-center">
                        <h4>Total: <span id="modalTotal" class="text-primary">₦0.00</span></h4>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="payment_method" id="payCash" value="cash" checked>
                            <label class="btn btn-outline-primary flex-fill" for="payCash">Cash</label>
                            <input type="radio" class="btn-check" name="payment_method" id="payCard" value="card">
                            <label class="btn btn-outline-primary flex-fill" for="payCard">Card</label>
                            <input type="radio" class="btn-check" name="payment_method" id="payTransfer" value="transfer">
                            <label class="btn btn-outline-primary flex-fill" for="payTransfer">Transfer</label>
                        </div>
                    </div>
                    <div id="cashFields" class="mb-3">
                        <label class="form-label">Amount Tendered (in kobo)</label>
                        <input type="number" name="amount_tendered" class="form-control form-control-lg" min="0" placeholder="Enter amount in kobo">
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Customer Name (optional)</label>
                        <input type="text" name="customer_name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Phone (optional)</label>
                        <input type="text" name="customer_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success btn-lg" onclick="submitCheckout()">Complete Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<script>
    let cart = [];
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function addToCart(id, name, price, qty = 1) {
        price = parseFloat(price);
        let existing = cart.find(i => i.product_id === id);
        if (existing) {
            existing.quantity += qty;
        } else {
            cart.push({ product_id: id, name, price, quantity: qty });
        }
        renderCart();
    }

    function removeFromCart(index) {
        cart.splice(index, 1);
        renderCart();
    }

    function renderCart() {
        let html = '';
        let total = 0;
        cart.forEach((item, i) => {
            let itemTotal = item.price * item.quantity;
            total += itemTotal;
            html += `<div class="cart-item">
                <div>
                    <div class="fw-semibold" style="font-size: 0.9rem;">${item.name}</div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <button class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="updateQty(${i}, -1)">-</button>
                        <span>${item.quantity}</span>
                        <button class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="updateQty(${i}, 1)">+</button>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-semibold">₦${itemTotal.toFixed(2)}</div>
                    <button class="btn btn-sm btn-link text-danger p-0" onclick="removeFromCart(${i})">&times;</button>
                </div>
            </div>`;
        });
        document.getElementById('cartItems').innerHTML = html || '<div class="text-center text-muted py-4"><p class="mb-1">Cart is empty</p><small>Search or click a product to add it</small></div>';
        document.getElementById('cartSubtotal').textContent = '₦' + total.toFixed(2);
        document.getElementById('cartTotal').textContent = '₦' + total.toFixed(2);
        document.getElementById('checkoutBtn').disabled = cart.length === 0;
    }

    function updateQty(index, delta) {
        cart[index].quantity += delta;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        renderCart();
    }

    function showCheckoutModal() {
        document.getElementById('modalTotal').textContent = document.getElementById('cartTotal').textContent;
        new bootstrap.Modal(document.getElementById('checkoutModal')).show();
    }

    function submitCheckout() {
        document.getElementById('checkoutItems').value = JSON.stringify(cart);
        document.getElementById('checkoutForm').submit();
    }

    document.getElementById('productSearch').addEventListener('input', function () {
        let query = this.value.trim();
        if (query.length < 2) {
            document.getElementById('searchResults').style.display = 'none';
            return;
        }
        fetch(`{{ $activeStore ? route('staff.pos.product.search', ['store' => $activeStore]) : '#' }}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ q: query })
        })
        .then(r => r.json())
        .then(data => {
            let results = document.getElementById('searchResults');
            if (data.products && data.products.length) {
                results.innerHTML = data.products.map(p =>
                    `<div class="p-2 border-bottom" style="cursor:pointer" onclick="addToCart('${p.id}','${p.name.replace(/'/g, "\\'")}',${p.amount},1);document.getElementById('searchResults').style.display='none';document.getElementById('productSearch').value='';">
                        <div class="fw-semibold">${p.name}</div>
                        <small class="text-primary">₦${parseFloat(p.amount).toFixed(2)} | Qty: ${p.quantity}</small>
                    </div>`
                ).join('');
                results.style.display = 'block';
            } else {
                results.innerHTML = '<div class="p-2 text-muted">No products found</div>';
                results.style.display = 'block';
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-box')) {
            document.getElementById('searchResults').style.display = 'none';
        }
    });

    document.querySelectorAll('input[name="payment_method"]').forEach(el => {
        el.addEventListener('change', function () {
            document.getElementById('cashFields').style.display = this.value === 'cash' ? 'block' : 'none';
        });
    });
</script>
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
