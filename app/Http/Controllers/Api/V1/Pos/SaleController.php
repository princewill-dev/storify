<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Enums\TransactionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
class SaleController extends Controller
{
    public function checkout(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Please open a POS session first.'], 400);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'nullable|in:cash,paystack,transfer',
            'amount_tendered' => 'nullable|integer|min:0',
            'paystack_reference' => 'nullable|string',
            'bank_account_id' => 'nullable|exists:store_banks,id',
            'payments' => 'nullable|array|min:1',
            'payments.*.method' => 'required|in:cash,paystack,transfer',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.amount_tendered' => 'nullable|integer|min:0',
            'payments.*.paystack_reference' => 'nullable|string',
            'payments.*.bank_account_id' => 'nullable|exists:store_banks,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email|max:255',
            'customer_address' => 'nullable|string|max:500',
            'customer_city' => 'nullable|string|max:100',
            'customer_state' => 'nullable|string|max:100',
            'customer_country' => 'nullable|string|max:100',
            'pin' => 'nullable|string|size:6',
            'service_charge_id' => 'nullable|exists:service_charges,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($user->pos_pin) {
            if (!$validated['pin'] || !Hash::check($validated['pin'], $user->pos_pin)) {
                return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
            }
        }

        $subtotal = 0;
        $orderItems = [];

        $productIds = collect($validated['items'])->pluck('product_id')->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($validated['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) continue;

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
        $serviceChargeAmount = 0;
        $serviceChargeName = null;

        if ($request->filled('service_charge_id')) {
            $charge = \App\Models\ServiceCharge::where('store_id', $store->id)->where('is_active', true)->find($validated['service_charge_id']);
            if ($charge) {
                $serviceChargeAmount = (float) $charge->amount;
                $serviceChargeName = $charge->name;
                $total += $serviceChargeAmount;
            }
        }

        $customerId = null;
        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerPhone = trim((string) ($validated['customer_phone'] ?? ''));
        $customerEmail = trim((string) ($validated['customer_email'] ?? ''));
        $customerAddress = trim((string) ($validated['customer_address'] ?? ''));
        $customerCity = trim((string) ($validated['customer_city'] ?? ''));
        $customerState = trim((string) ($validated['customer_state'] ?? ''));
        $customerCountry = trim((string) ($validated['customer_country'] ?? ''));

        if ($customerName !== '' || $customerPhone !== '') {
            $customerData = [
                'first_name' => $customerName !== '' ? $customerName : 'Walk-in',
                'last_name' => '',
                'email' => $customerEmail !== '' ? $customerEmail : ('pos-' . \Illuminate\Support\Str::random(8) . '@walkin.local'),
                'status' => 'active',
                'password' => \Illuminate\Support\Str::random(32),
                'street_address' => $customerAddress !== '' ? $customerAddress : null,
                'city' => $customerCity !== '' ? $customerCity : null,
                'state' => $customerState !== '' ? $customerState : null,
                'country' => $customerCountry !== '' ? $customerCountry : 'Nigeria',
            ];

            if ($customerEmail !== '' && !str_contains($customerEmail, '@walkin.local')) {
                $customer = \App\Models\Customer::where('email', $customerEmail)->first();
            }

            if (!isset($customer) || !$customer) {
                $customer = \App\Models\Customer::firstOrCreate(
                    ['phone' => $customerPhone !== '' ? $customerPhone : null, 'business_id' => $store->business_id],
                    $customerData
                );
            }

            if ($customerPhone !== '' && $customer->phone !== $customerPhone) {
                $customer->update(['phone' => $customerPhone]);
            }

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
            'amount_paid' => $total,
            'service_charge_amount' => $serviceChargeAmount > 0 ? $serviceChargeAmount : null,
            'status' => 'completed',
            'notes' => $validated['notes'] ?? null,
            'meta' => [
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'customer_email' => $validated['customer_email'] ?? null,
                'service_charge_id' => $validated['service_charge_id'] ?? null,
                'service_charge_name' => $serviceChargeName,
                'split_payment' => isset($validated['payments']) ? collect($validated['payments'])->map(fn($p) => [
                    'method' => $p['method'],
                    'amount' => (float) $p['amount'],
                ])->toArray() : null,
            ],
        ]);

        $order->items()->saveMany($orderItems);

        // Build payment legs — support new payments[] and legacy single payment_method
        $payments = $validated['payments'] ?? null;
        if (!$payments) {
            $payments = [[
                'method' => $validated['payment_method'],
                'amount' => $total,
                'amount_tendered' => $validated['amount_tendered'] ?? null,
                'paystack_reference' => $validated['paystack_reference'] ?? null,
                'bank_account_id' => $validated['bank_account_id'] ?? null,
            ]];
        }

        $paymentsSum = collect($payments)->sum(fn($p) => (float) $p['amount']);
        if (abs($paymentsSum - $total) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amounts (' . number_format($paymentsSum, 2) . ') do not match order total (' . number_format($total, 2) . ').',
            ], 422);
        }

        $paymentMethods = \App\Models\PaymentMethod::whereIn('code', ['paystack', 'bank_transfer'])->get()->keyBy('code');

        foreach ($payments as $leg) {
            $legMethod = $leg['method'];
            $legAmount = (float) $leg['amount'];

            if ($legMethod === 'transfer' && empty($leg['bank_account_id'])) {
                return response()->json(['success' => false, 'message' => 'A bank account is required for bank transfer payments.'], 422);
            }

            $txnReference = 'TXN-POS-' . strtoupper(\Illuminate\Support\Str::random(10));
            $paymentMethodId = null;
            $storeBankId = null;

            if ($legMethod === 'paystack') {
                $paymentMethodId = $paymentMethods->get('paystack')?->id;
                if (!empty($leg['paystack_reference'])) {
                    $txnReference = $leg['paystack_reference'];
                }
            } elseif ($legMethod === 'transfer') {
                $paymentMethodId = $paymentMethods->get('bank_transfer')?->id;
                $storeBankId = $leg['bank_account_id'] ?? null;
            }

            Transaction::create([
                'reference' => $txnReference,
                'order_id' => $order->id,
                'business_id' => $store->business_id,
                'store_bank_id' => $storeBankId,
                'payment_method_id' => $paymentMethodId,
                'amount' => $legAmount,
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'leg_method' => $legMethod,
                    'amount_tendered' => $leg['amount_tendered'] ?? null,
                    'is_split' => count($payments) > 1,
                ],
            ]);

            $store->creditBalance((int) round($legAmount * 100));
        }

        if ($customerEmail !== '') {
            try {
                \Mail::to($customerEmail)->queue(new \App\Mail\PosReceiptMail($order));
            } catch (\Throwable $e) {
                Log::error('pos_receipt_email_failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        $ledger = app(\App\Services\StockLedgerService::class);
        $stockLocs = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
            ->where('locationable_id', $store->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($validated['items'] as $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) continue;

            $stockLoc = $stockLocs[$product->id] ?? null;

            if ($stockLoc && $stockLoc->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
                $ledger->recordRemoval($stockLoc, (int) $item['quantity'], $order, $user, 'POS sale — Order #' . $order->order_number);
            } elseif ($product->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
                $stockLoc = \App\Models\StockLocation::firstOrCreate([
                    'product_id' => $product->id,
                    'locationable_type' => \App\Models\Store::class,
                    'locationable_id' => $store->id,
                ], ['quantity' => $product->quantity + (int) $item['quantity'], 'business_id' => $store->business_id]);
                $ledger->recordRemoval($stockLoc, (int) $item['quantity'], $order, $user, 'POS sale — Order #' . $order->order_number);
            }
        }

        $order->load(['items', 'transactions.paymentMethod']);
        $amountTendered = (int) collect($payments)->sum(fn($p) => (int) ($p['amount_tendered'] ?? 0));
        $change = $amountTendered > 0 ? max(0, $amountTendered - (int) $total) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'total' => (int) $total,
                    'amount_tendered' => $amountTendered,
                    'change' => $change,
                    'date' => $order->created_at->toISOString(),
                    'cashier' => $user->name,
                    'payment_method' => count($payments) === 1 ? $payments[0]['method'] : 'split',
                    'payments' => $order->transactions->map(fn($tx) => [
                        'method' => $tx->metadata['leg_method'] ?? ($tx->paymentMethod?->code ?? 'cash'),
                        'method_label' => $tx->paymentMethod?->name ?? 'Cash',
                        'amount' => (float) $tx->amount,
                    ]),
                    'store_name' => $store->name,
                    'store_address' => $store->address,
                    'customer_name' => $validated['customer_name'] ?? null,
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'service_charge_name' => $serviceChargeName,
                    'service_charge_amount' => $serviceChargeAmount,
                    'items' => $order->items->map(fn($i) => [
                        'name' => $i->product_name,
                        'qty' => $i->quantity,
                        'price' => (float) $i->unit_price,
                        'subtotal' => (float) $i->subtotal,
                    ]),
                ],
            ],
        ], 201);
    }

    public function history(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $query = Order::where('store_id', $store->id)
            ->where('source', 'pos')
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->q;
                $q->where(function ($x) use ($search) {
                    $x->where('order_number', 'like', "%{$search}%")
                      ->orWhereHas('items', fn($i) => $i->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->with(['items', 'transactions.paymentMethod']);

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if ($session) {
            $query->where('pos_session_id', $session->id);
        } else {
            $query->where('staff_id', $user->id);
        }

        $orders = $query->latest()->paginate(20)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $orders->map(fn($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'total' => (float) $o->total,
                    'status' => $o->status instanceof \App\Enums\OrderStatus ? $o->status->value : $o->status,
                    'created_at' => $o->created_at->toISOString(),
                    'items_count' => $o->items->count(),
                    'items' => $o->items->take(3)->map(fn($i) => $i->product_name),
                    'more_items' => $o->items->count() > 3 ? $o->items->count() - 3 : 0,
                    'has_refund' => $o->transactions->whereIn('status', ['refunded', 'refund_pending'])->isNotEmpty(),
                    'refund_status' => $o->transactions->whereIn('status', ['refunded', 'refund_pending'])->first()?->status?->value ?? null,
                ]),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ],
        ]);
    }

    public function receipt(Request $request, Store $store, $orderId): JsonResponse
    {
        $order = \App\Models\Order::findOrFail($orderId);

        if ($order->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $order->load(['items', 'transactions.paymentMethod', 'customer']);

        $transaction = $order->transactions->first();
        $meta = $order->meta ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'total' => (float) $order->total,
                    'status' => $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status,
                    'date' => $order->created_at->toISOString(),
                    'created_at' => $order->created_at->toISOString(),
                    'store_name' => $store->name,
                    'store_address' => $store->address,
                    'payment_method' => $transaction?->paymentMethod?->name ?? 'Cash',
                    'reference' => $transaction?->reference,
                    'customer_name' => $meta['customer_name'] ?? $order->customer?->full_name ?? null,
                    'customer_phone' => $meta['customer_phone'] ?? $order->customer?->phone ?? null,
                    'amount_tendered' => (int) ($meta['amount_tendered'] ?? 0),
                    'change' => (int) ($meta['amount_tendered'] ?? 0) > 0 ? max(0, (int) ($meta['amount_tendered'] ?? 0) - (int) $order->total) : 0,
                    'service_charge_name' => $meta['service_charge_name'] ?? null,
                    'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
                    'items' => $order->items->map(fn($i) => [
                        'name' => $i->product_name,
                        'qty' => $i->quantity,
                        'price' => (float) $i->unit_price,
                        'subtotal' => (float) $i->subtotal,
                    ]),
                ],
            ],
        ]);
    }

    public function refund(Request $request, Store $store, $orderId): JsonResponse
    {
        $user = $request->user();
        $order = \App\Models\Order::findOrFail($orderId);

        if ($order->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Order does not belong to this store.'], 403);
        }

        $existingTx = $order->transactions()->where('status', 'confirmed')->first();
        if (!$existingTx) {
            return response()->json(['success' => false, 'message' => 'Only confirmed orders can be refunded.'], 400);
        }

        $alreadyRefunded = $order->transactions()
            ->whereIn('status', ['refunded', 'refund_pending'])
            ->exists();
        if ($alreadyRefunded) {
            return response()->json(['success' => false, 'message' => 'A refund has already been requested for this order.'], 400);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        Transaction::create([
            'reference' => 'RFND-' . strtoupper(\Illuminate\Support\Str::random(10)),
            'order_id' => $order->id,
            'payment_method_id' => $existingTx->payment_method_id,
            'amount' => $order->total,
            'status' => TransactionStatus::REFUND_PENDING,
            'metadata' => [
                'refund_reason' => $validated['reason'],
                'refund_requested_by' => $user->id,
                'refund_requested_at' => now()->toDateTimeString(),
                'original_transaction_id' => $existingTx->id,
            ],
        ]);

        Log::info('pos.refund_requested', [
            'order_id' => $order->id,
            'staff_id' => $user->id,
            'store_id' => $store->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Refund requested. Awaiting admin approval.',
        ], 201);
    }
}
