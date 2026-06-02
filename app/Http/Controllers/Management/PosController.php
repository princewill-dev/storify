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

        $allStores = ($user->isStaff() ? $user->assignedStores : $user->stores)->sortBy('name');

        return view('management.pos.index', compact(
            'user', 'sessions', 'openSessionsCount', 'todaySales', 'allStores',
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

        $paystack = $store->paymentGateways()
                ->where('gateway', 'paystack')
                ->where('is_active', true)
                ->first();

            if (!$paystack) {
                $paystack = \App\Models\BusinessGateway::where('business_id', $store->business_id)
                    ->where('gateway', 'paystack')
                    ->where('is_active', true)
                    ->first();
            }

            if ($paystack) {
                $paymentMethods[] = ['id' => 'card', 'label' => 'Card (Paystack)', 'icon' => 'credit-card'];
                $paystackKey = $paystack->public_key;
            }

            if ($store->banks()->exists()) {
            $paymentMethods[] = ['id' => 'transfer', 'label' => 'Bank Transfer', 'icon' => 'building'];
            $bankAccounts = $store->banks()->where('is_verified', true)->get();
        }

        return view('management.pos.terminal', compact(
            'user', 'store', 'session', 'products', 'paymentMethods', 'paystackKey', 'bankAccounts',
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

        return view('management.pos.show', compact(
            'user', 'session', 'orders', 'staffSessions', 'totalSales', 'orderCount',
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
            'payment_method' => 'required|in:cash,card,transfer',
            'amount_tendered' => 'nullable|numeric|min:0',
            'paystack_reference' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);

        $subtotal = 0;
        $orderItems = [];

        foreach ($validated['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
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

        $walkInCustomer = \App\Models\Customer::firstOrCreate(
            ['email' => 'walkin@pos.local', 'business_id' => $store->business_id],
            ['first_name' => 'Walk-in', 'last_name' => 'Customer', 'phone' => '0000000000', 'status' => 'active', 'password' => \Illuminate\Support\Str::random(32)]
        );

        $order = Order::create([
            'store_id' => $store->id,
            'user_id' => $store->user_id,
            'business_id' => $store->business_id,
            'customer_id' => $walkInCustomer->id,
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

        $txnStatus = $validated['payment_method'] === 'transfer' ? 'pending' : 'confirmed';
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
            'payment_method_id' => $paymentMethod?->id,
            'amount' => $total,
            'status' => $txnStatus,
        ]);

        if ($txnStatus === 'confirmed') {
            $store->creditBalance((int) round($total));
        }

        foreach ($validated['items'] as $item) {
            $product = Product::find($item['product_id']);
            if ($product && $product->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
                \App\Models\StockMovement::create([
                    'product_id' => $product->id,
                    'from_location_type' => \App\Models\Store::class,
                    'from_location_id' => $store->id,
                    'quantity' => (int) $item['quantity'],
                    'type' => \App\Enums\StockMovementType::REMOVED->value,
                    'reference_type' => \App\Models\Order::class,
                    'reference_id' => $order->id,
                    'performed_by_type' => \App\Models\User::class,
                    'performed_by_id' => $user->id,
                    'notes' => 'POS sale — Order #' . $order->order_number,
                ]);
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

        return view('management.pos.receipt', compact('user', 'store', 'order'));
    }
}
