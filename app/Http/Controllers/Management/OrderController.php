<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{


    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $query = Order::query()
            ->with(['customer', 'store', 'items'])
            ->where('user_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $status = $request->payment_status;
            // Map generic payment statuses to transaction statuses
            $transactionStatus = match($status) {
                'unpaid' => null, // Special case
                'pending' => \App\Enums\TransactionStatus::PENDING->value,
                'paid' => \App\Enums\TransactionStatus::CONFIRMED->value,
                'refunded' => \App\Enums\TransactionStatus::REFUNDED->value,
                'failed' => \App\Enums\TransactionStatus::CANCELED->value, // Failed maps to canceled
                default => $status,
            };

            if ($status === 'unpaid') {
                $query->whereDoesntHave('transactions');
            } else {
                $query->whereHas('transactions', function ($q) use ($transactionStatus) {
                    $q->where('status', $transactionStatus);
                });
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $selectedPublicStoreId = $request->query('store_id');
        $selectedStore = null;
        if ($selectedPublicStoreId) {
            $selectedStore = $user->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->first();
            
            if ($selectedStore) {
                $query->where('store_id', $selectedStore->id);
            }
        }

        $orders = $query->latest()->paginate(20)->withQueryString();

        $stats = [
            'total' => Order::where('user_id', $user->id)->count(),
            'pending' => Order::where('user_id', $user->id)->where('status', 'pending')->count(),
            'processing' => Order::where('user_id', $user->id)->where('status', 'processing')->count(),
            'dispatched' => Order::where('user_id', $user->id)->where('status', 'dispatched')->count(),
            'delivered' => Order::where('user_id', $user->id)->where('status', 'delivered')->count(),
            'completed' => Order::where('user_id', $user->id)->where('status', 'completed')->count(),
            'cancelled' => Order::where('user_id', $user->id)->where('status', 'cancelled')->count(),
            'returned' => Order::where('user_id', $user->id)->where('status', 'returned')->count(),
            'total_revenue' => Order::where('user_id', $user->id)
                ->whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CONFIRMED))
                ->sum('total'),
        ];

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();

        return view('management.orders.index', compact('orders', 'stats', 'user', 'stores', 'selectedStore'));
    }

    public function show(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $order->load(['items.product', 'customer', 'transactions.paymentMethod', 'deliveryRoute', 'store']);
        $activityLogs = ActivityLog::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user')
            ->latest()
            ->get();

        return view('management.orders.show', compact('order', 'activityLogs', 'user'));
    }

    public function edit(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $order->load(['items', 'customer', 'deliveryRoute']);
        return view('management.orders.edit', compact('order', 'user'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $data = $request->validate([
            'shipping_fee' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $order->shipping_fee = $data['shipping_fee'];
        $order->tax = $data['tax'];
        $order->notes = $data['notes'] ?? null;
        $order->total = (float)$order->subtotal + $order->shipping_fee + $order->tax;
        $order->save();

        return back()->with('success', 'Order updated.');
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $data = $request->validate(['status' => ['required', 'in:pending,accepted,processing,dispatched,delivered,completed,cancelled,returned']]);
        $order->status = $data['status'];
        $order->save();

        return back()->with('success', 'Order status updated.');
    }

    public function updatePaymentStatus(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $data = $request->validate(['payment_status' => ['required', 'string']]);
        
        // Find existing transaction or create new one
        $transaction = $order->transaction()->first(); // Check accessor logic, but here direct relation
        
        // Map selected payment status to TransactionStatus
        $newStatus = match($data['payment_status']) {
            'pending' => \App\Enums\TransactionStatus::PENDING,
            'paid' => \App\Enums\TransactionStatus::CONFIRMED,
            'refunded' => \App\Enums\TransactionStatus::REFUNDED,
            'failed' => \App\Enums\TransactionStatus::CANCELED,
            'unpaid' => null, // Special handling
            default => \App\Enums\TransactionStatus::PENDING,
        };

        if ($data['payment_status'] === 'unpaid') {
            // Option 1: Delete transaction? 
            // Option 2: Set to pending?
            // Decision: If manually marking unpaid, maybe we delete the transaction or set to pending. 
            // For now, let's just set it to pending if it exists, or do nothing.
             if ($transaction) {
                 $transaction->delete(); // Or set to pending
             }
        } elseif ($newStatus) {
             if ($transaction) {
                $transaction->update(['status' => $newStatus]);
             } else {
                // Create a catch-all transaction method (e.g. Cash/Manual)
                $paymentMethod = \App\Models\PaymentMethod::where('code', 'cash')->first() 
                    ?? \App\Models\PaymentMethod::first();
                
                $order->transactions()->create([
                    'payment_method_id' => $paymentMethod?->id,
                    'amount' => $order->total,
                    'currency' => 'NGN', // Default
                    'status' => $newStatus,
                    'reference' => 'MAN-' . strtoupper(\Illuminate\Support\Str::random(10)),
                ]);
             }
        }

        return back()->with('success', 'Payment status updated.');
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $order->delete();

        return redirect()->route('management.orders.index', ['user' => $user])->with('success', 'Order deleted.');
    }
}
