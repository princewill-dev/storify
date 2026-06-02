<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSession;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PosSaleController extends Controller
{
    public function searchProducts(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = $request->input('q', '');
        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('product_code', 'like', "%{$query}%");
            })
            ->with('primaryImage')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'amount' => (float) $product->amount,
                    'quantity' => (int) $product->quantity,
                    'image' => $product->primaryImage?->path
                        ? asset('storage/' . $product->primaryImage->path)
                        : null,
                ];
            });

        return response()->json(['products' => $products]);
    }

    public function checkout(Request $request, Store $store): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            return back()->with('error', 'Please open a POS session first.');
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

        $txnStatus = 'confirmed';
        $txnReference = 'TXN-POS-' . strtoupper(\Illuminate\Support\Str::random(10));
        $paymentMethodId = null;

        if ($validated['payment_method'] === 'transfer') {
            $txnStatus = 'pending';
        }

        if ($validated['payment_method'] === 'card' && $request->filled('paystack_reference')) {
            $txnReference = $validated['paystack_reference'];
            $txnStatus = 'confirmed';
        }

        $paystackMethod = \App\Models\PaymentMethod::where('code', 'paystack')->first();
        $cashMethod = \App\Models\PaymentMethod::where('code', 'cash')->first();
        $transferMethod = \App\Models\PaymentMethod::where('code', 'bank_transfer')->first();

        $paymentMethodId = match ($validated['payment_method']) {
            'card' => $paystackMethod?->id,
            'transfer' => $transferMethod?->id,
            default => $cashMethod?->id,
        };

        Transaction::create([
            'reference' => $txnReference,
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethodId,
            'amount' => $total,
            'status' => $txnStatus,
        ]);

        if ($txnStatus === 'confirmed') {
            $store->creditBalance((int) round($total));
        }

        $ledger = app(\App\Services\StockLedgerService::class);

        foreach ($validated['items'] as $item) {
            $product = Product::find($item['product_id']);
            $stockLoc = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
                ->where('locationable_id', $store->id)
                ->where('product_id', $product->id)
                ->first();

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

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'order_number' => $order->order_number,
                'total' => $total,
                'change' => $validated['amount_tendered']
                    ? max(0, (int) $validated['amount_tendered'] - $total)
                    : 0,
                'redirect' => route('staff.pos.receipt', ['store' => $store, 'order' => $order]),
            ]);
        }

        return redirect()->route('staff.pos.receipt', ['store' => $store, 'order' => $order])
            ->with('success', 'Sale completed. Order #' . $order->order_number);
    }

    public function refund(Request $request, Store $store, Order $order): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('staff.login');
        }

        if ($order->store_id !== $store->id) {
            abort(403, 'Order does not belong to this store.');
        }

        $existingTx = $order->transactions()->where('status', 'confirmed')->first();
        if (!$existingTx) {
            return back()->with('error', 'Only confirmed orders can be refunded.');
        }

        $alreadyRefunded = $order->transactions()
            ->whereIn('status', ['refunded', 'refund_pending'])
            ->exists();
        if ($alreadyRefunded) {
            return back()->with('error', 'A refund has already been requested for this order.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        \App\Models\Transaction::create([
            'reference' => 'RFND-' . strtoupper(\Illuminate\Support\Str::random(10)),
            'order_id' => $order->id,
            'payment_method_id' => $existingTx->payment_method_id,
            'amount' => $order->total,
            'status' => \App\Enums\TransactionStatus::REFUND_PENDING->value,
            'metadata' => [
                'refund_reason' => $validated['reason'],
                'refund_requested_by' => $user->id,
                'refund_requested_at' => now()->toDateTimeString(),
                'original_transaction_id' => $existingTx->id,
            ],
        ]);

        \Log::info('pos.refund_requested', [
            'order_id' => $order->id,
            'staff_id' => $user->id,
            'store_id' => $store->id,
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'Refund requested. Awaiting admin approval.');
    }

    public function receipt(Request $request, Store $store, Order $order): View
    {
        if (!$request->user()) {
            abort(403);
        }

        $order->load('items');

        return view('staff.pos.receipt', compact('store', 'order'));
    }
}
