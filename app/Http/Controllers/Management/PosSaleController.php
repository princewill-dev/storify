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
                    'image' => $product->primaryImage?->image_path
                        ? asset('storage/' . $product->primaryImage->image_path)
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

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,card,transfer',
            'amount_tendered' => 'nullable|integer|min:0',
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

        $order = Order::create([
            'store_id' => $store->id,
            'user_id' => $store->user_id,
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

        Transaction::create([
            'reference' => 'TXN-POS-' . strtoupper(\Illuminate\Support\Str::random(10)),
            'order_id' => $order->id,
            'amount' => $total,
            'status' => 'confirmed',
            'store_balance_before' => $store->balance,
            'store_balance_after' => $store->balance + $total,
        ]);

        $store->creditBalance((int) round($total));

        foreach ($validated['items'] as $item) {
            $product = Product::find($item['product_id']);
            if ($product && $product->quantity >= (int) $item['quantity']) {
                $product->decrement('quantity', (int) $item['quantity']);
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

    public function receipt(Request $request, Store $store, Order $order): View
    {
        if (!$request->user()) {
            abort(403);
        }

        $order->load('items');

        return view('staff.pos.receipt', compact('store', 'order'));
    }
}
