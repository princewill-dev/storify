<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $storeIds = $user->isStaff() ? $user->assignedStores()->pluck('stores.id') : $user->stores()->pluck('id');

        $sessionsQuery = PosSession::whereIn('store_id', $storeIds)
            ->with(['store', 'staff']);

        if ($request->filled('store_id')) {
            $store = ($user->isStaff() ? $user->assignedStores() : $user->stores())
                ->where('store_id', $request->query('store_id'))->first();
            if ($store) {
                $sessionsQuery->where('store_id', $store->id);
            }
        }

        if ($request->filled('status') && in_array($request->query('status'), ['open', 'closed'])) {
            $sessionsQuery->where('status', $request->query('status'));
        }

        $sessions = $sessionsQuery->withCount('orders')->latest()->paginate(20)->withQueryString();

        $openSessionsCount = PosSession::whereIn('store_id', $storeIds)
            ->where('status', PosSession::STATUS_OPEN)->count();

        $todaySales = Order::whereIn('store_id', $storeIds)
            ->whereNotNull('pos_session_id')
            ->whereDate('created_at', today())->sum('total');

        $allStores = ($user->isStaff() ? $user->assignedStores : $user->stores)->where('status', '!=', 'deleted')->sortBy('name');

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'POS Sessions'],
        ];

        return view('management.pos.index', compact(
            'user', 'sessions', 'openSessionsCount', 'todaySales', 'allStores', 'breadcrumbs',
        ));
    }

    public function terminal(Request $request, Store $store): View
    {
        $user = $request->user();

        if ($user->isStaff()) {
            if (!$user->assignedStores()->where('stores.id', $store->id)->exists()) {
                abort(403);
            }
        } elseif ($store->user_id !== $user->id) {
            abort(403);
        }

        if (!$store->pos_enabled) {
            return redirect()->route('management.pos.index')
                ->with('error', 'POS is not enabled for this store.');
        }

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            $session = PosSession::create([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'staff_id' => $user->id,
                'opened_at' => now(),
                'opening_balance' => 0,
                'status' => PosSession::STATUS_OPEN,
            ]);
        }

        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->latest()
            ->take(30)
            ->get();

        $paymentMethods = [];
        $paystackKey = null;
        $bankAccounts = collect();

        $pid = \App\Models\PaymentMethod::where('code', 'paystack')->value('id');
        $sid = DB::table('store_payment_method')->where('store_id', $store->id)
            ->where('payment_method_id', $pid)->where('is_active', true)->exists();
        $bizRow = DB::table('business_payment_method')->where('business_id', $store->business_id)
            ->where('payment_method_id', $pid)->where('is_active', true)->first();
        $paystack = null;
        if ($sid && $bizRow) {
            $cfg = json_decode($bizRow->config, true);
            $paystack = (object)['public_key' => $cfg['public_key'] ?? null];
        } elseif ($bizRow) {
            $cfg = json_decode($bizRow->config, true);
            $paystack = (object)['public_key' => $cfg['public_key'] ?? null];
        }

            if ($paystack) {
                $paymentMethods[] = ['id' => 'paystack', 'label' => 'Paystack', 'icon' => 'credit-card'];
                $paystackKey = $paystack->public_key;
            }

            if ($store->banks()->exists()) {
            $paymentMethods[] = ['id' => 'transfer', 'label' => 'Bank Transfer', 'icon' => 'building'];
            $bankAccounts = $store->banks()->where('is_verified', true)->get();
        }

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'POS Sessions', 'url' => route('management.pos.index')],
            ['label' => $store->name],
        ];

        return view('management.pos.terminal', compact(
            'user', 'store', 'session', 'products', 'paymentMethods', 'paystackKey', 'bankAccounts', 'breadcrumbs',
        ));
    }

    public function show(Request $request, PosSession $session): View
    {
        $user = $request->user();

        if ($user->isStaff()) {
            if (!$user->assignedStores()->where('stores.id', $session->store->id)->exists()) {
                abort(403);
            }
        } elseif ($session->store->user_id !== $user->id) {
            abort(403);
        }

        $session->load(['store', 'staff']);

        $orders = $session->orders()->with(['transactions.paymentMethod', 'items'])->latest()->get();
        $totalSales = $orders->sum('total');
        $orderCount = $orders->count();

        $staffSessions = PosSession::where('staff_id', $session->staff_id)
            ->whereIn('store_id', $user->isStaff()
                ? $user->assignedStores()->pluck('id')
                : $user->stores()->pluck('id'))
            ->with('store')
            ->latest()
            ->take(20)
            ->get();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'POS Sessions', 'url' => route('management.pos.index')],
            ['label' => $session->store->name],
        ];

        return view('management.pos.show', compact(
            'user', 'session', 'orders', 'staffSessions', 'totalSales', 'orderCount', 'breadcrumbs',
        ));
    }

    public function checkout(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if ($user->isStaff()) {
            if (!$user->assignedStores()->where('stores.id', $store->id)->exists()) {
                abort(403);
            }
        } elseif ($store->user_id !== $user->id) {
            abort(403);
        }

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            return back()->with('error', 'No active POS session. Please reopen the terminal.');
        }

        if (is_string($request->input('items'))) {
            $request->merge(['items' => json_decode($request->input('items'), true) ?? []]);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,paystack,transfer',
            'amount_tendered' => 'nullable|numeric|min:0',
            'paystack_reference' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);

        $subtotal = 0;
        $orderItems = [];

        // Preload all products in a single query to avoid N+1
        $productIds = collect($validated['items'])->pluck('product_id')->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($validated['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) {
                continue;
            }
            $price = (float) $product->amount;
            $qty = (int) $item['quantity'];
            $itemTotal = $price * $qty;
            $subtotal += $itemTotal;

            $orderItems[] = new OrderItem([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $price,
                'quantity' => $qty,
                'subtotal' => $itemTotal,
            ]);
        }

        $total = $subtotal;

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerPhone = trim((string) ($validated['customer_phone'] ?? ''));
        $customerId = null;

        if ($customerName !== '' || $customerPhone !== '') {
            $customer = \App\Models\Customer::firstOrCreate(
                ['phone' => $customerPhone !== '' ? $customerPhone : null, 'business_id' => $store->business_id],
                ['first_name' => $customerName !== '' ? $customerName : 'Walk-in', 'last_name' => '', 'email' => 'pos-' . \Illuminate\Support\Str::random(8) . '@walkin.local', 'status' => 'active', 'password' => \Illuminate\Support\Str::random(32)]
            );
            $customerId = $customer->id;
        }

        $order = Order::create([
            'store_id' => $store->id,
            'user_id' => $store->user_id,
            'business_id' => $store->business_id,
            'customer_id' => $customerId,
            'source' => 'pos',
            'staff_id' => $user->id,
            'pos_session_id' => $session->id,
            'subtotal' => $subtotal,
            'total' => $total,
            'status' => 'completed',
            'notes' => $validated['notes'] ?? null,
            'meta' => [
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'payment_method' => $validated['payment_method'],
                'amount_tendered' => $validated['amount_tendered'] ?? null,
            ],
        ]);

        $order->items()->saveMany($orderItems);

        $txnStatus = 'confirmed';
        $txnReference = 'TXN-POS-' . strtoupper(\Illuminate\Support\Str::random(10));

        if ($validated['payment_method'] === 'card' && $request->filled('paystack_reference')) {
            $txnReference = $validated['paystack_reference'];
        }

        $methodCode = match ($validated['payment_method']) {
            'card' => 'paystack',
            'transfer' => 'bank_transfer',
            default => 'cash',
        };
        $paymentMethod = \App\Models\PaymentMethod::where('code', $methodCode)->first();

        Transaction::create([
            'reference' => $txnReference,
            'order_id' => $order->id,
            'business_id' => $store->business_id,
            'payment_method_id' => $paymentMethod?->id,
            'amount' => $total,
            'status' => $txnStatus,
        ]);

        if ($txnStatus === 'confirmed') {
            $store->creditBalance((int) round($total * 100));
        }

        $ledger = app(\App\Services\StockLedgerService::class);

        // Preload all stock locations for this store in a single query
        $stockLocs = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
            ->where('locationable_id', $store->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($validated['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) {
                continue;
            }
            $stockLoc = $stockLocs[$product->id] ?? null;

            if ($stockLoc && $stockLoc->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
                $ledger->recordRemoval($stockLoc, (int) $item['quantity'], $order, $user, 'POS sale — Order #' . $order->order_number);
            } elseif ($product && $product->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
                $stockLoc = \App\Models\StockLocation::firstOrCreate([
                    'product_id' => $product->id,
                    'locationable_type' => \App\Models\Store::class,
                    'locationable_id' => $store->id,
                ], ['quantity' => $product->quantity + (int) $item['quantity'], 'business_id' => $store->business_id]);
                $ledger->recordRemoval($stockLoc, (int) $item['quantity'], $order, $user, 'POS sale — Order #' . $order->order_number);
            }
        }

        return redirect()->route('management.pos.receipt', ['store' => $store, 'order' => $order])
            ->with('success', 'Sale completed. Order #' . $order->order_number);
    }

    public function receipt(Request $request, Store $store, Order $order): View
    {
        $user = $request->user();

        if ($user->isStaff()) {
            if (!$user->assignedStores()->where('stores.id', $store->id)->exists()) {
                abort(403);
            }
        } elseif ($store->user_id !== $user->id) {
            abort(403);
        }

        $order->load(['items', 'transactions.paymentMethod']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'POS Sessions', 'url' => route('management.pos.index')],
            ['label' => 'Receipt'],
        ];

        return view('management.pos.receipt', compact('user', 'store', 'order', 'breadcrumbs'));
    }
}
