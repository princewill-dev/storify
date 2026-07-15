<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\StockLocation;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Services\StockLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OrderController extends Controller
{


    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $query = Order::query()
            ->with(['customer', 'store', 'items', 'staff']);
        $this->forBusiness($query, $user);
        if ($user->isRestrictedStaff()) {
            $query->whereIn('store_id', $user->assignedStores()->pluck('id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
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

        $orders = $query->latest()->paginate(20)->withQueryString();

        $statsQuery = Order::where('business_id', $user->business_id);
        $this->forBusiness($statsQuery, $user);

        $orderStats = Order::where('business_id', $user->business_id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(status = 'pending') as pending,
                SUM(status = 'accepted') as accepted,
                SUM(status = 'processing') as processing,
                SUM(status = 'dispatched') as dispatched,
                SUM(status = 'delivered') as delivered,
                SUM(status = 'completed') as completed,
                SUM(status = 'cancelled') as cancelled,
                SUM(status = 'returned') as returned
            ")->first();

        $stats = [
            'total' => (int) $orderStats->total,
            'pending' => (int) $orderStats->pending,
            'accepted' => (int) $orderStats->accepted,
            'processing' => (int) $orderStats->processing,
            'dispatched' => (int) $orderStats->dispatched,
            'delivered' => (int) $orderStats->delivered,
            'completed' => (int) $orderStats->completed,
            'cancelled' => (int) $orderStats->cancelled,
            'returned' => (int) $orderStats->returned,
        ];

        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $statusOptions = \App\Enums\OrderStatus::cases();
        $activeFilters = $request->only(['search', 'status', 'store_id', 'source', 'date_from', 'date_to']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Orders'],
        ];

        return view('management.orders.index', compact('orders', 'stats', 'user', 'stores', 'statusOptions', 'activeFilters', 'breadcrumbs'));
    }

    public function show(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $order->load(['items.product', 'customer', 'transactions.paymentMethod', 'deliveryRoute', 'store', 'staff', 'delivery']);
        $activityLogs = ActivityLog::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user')
            ->latest()
            ->get();

        // Delivery agents for dispatch modal
        $deliveryAgents = User::where('business_id', $order->business_id)
            ->role('Delivery Agent')
            ->where('status', 'active')
            ->get(['id', 'name', 'phone']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Orders', 'url' => route('management.orders.index')],
            ['label' => $order->order_number],
        ];

        return view('management.orders.show', compact('order', 'activityLogs', 'user', 'breadcrumbs', 'deliveryAgents'));
    }

    public function edit(Request $request, Order $order): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || $order->user_id !== $user->id) {
            return redirect()->route('management.auth.login');
        }

        $order->load(['items', 'customer', 'deliveryRoute']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Orders', 'url' => route('management.orders.index')],
            ['label' => $order->order_number, 'url' => route('management.orders.show', $order)],
            ['label' => 'Edit'],
        ];

        return view('management.orders.edit', compact('order', 'user', 'breadcrumbs'));
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

    public function acceptOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if ($order->status !== OrderStatus::PENDING) {
            return back()->with('error', 'Only pending orders can be accepted.');
        }

        $order->update(['status' => OrderStatus::ACCEPTED]);
        $this->logActivity($order, $user, 'accepted', 'Order accepted');

        $this->notifyOrderUpdate($order, 'accepted');

        return back()->with('success', 'Order #' . $order->order_number . ' has been accepted.');
    }

    public function processOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if ($order->status !== OrderStatus::ACCEPTED) {
            return back()->with('error', 'Only accepted orders can be moved to processing.');
        }

        $order->update(['status' => OrderStatus::PROCESSING]);
        $this->logActivity($order, $user, 'processing', 'Order moved to processing');

        $this->notifyOrderUpdate($order, 'processing');

        return back()->with('success', 'Order #' . $order->order_number . ' is now being processed.');
    }

    public function dispatchOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if ($order->status !== OrderStatus::PROCESSING) {
            return back()->with('error', 'Only processing orders can be dispatched.');
        }

        $validated = $request->validate([
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:20',
            'tracking_number' => 'nullable|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'estimated_delivery_at' => 'nullable|date',
        ]);

        DB::transaction(function () use ($order, $user, $validated) {
            // Update order status
            $order->update(['status' => OrderStatus::DISPATCHED]);

            // Note: Stock was already reduced at checkout (CheckoutController)
            // or POS sale (PosController/PosSaleController). No duplicate removal needed.

            // Create delivery record
            OrderDelivery::create([
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'status' => 'assigned',
                'delivery_route_id' => $order->delivery_route_id,
                'driver_name' => $validated['driver_name'] ?? null,
                'driver_phone' => $validated['driver_phone'] ?? null,
                'delivery_notes' => $validated['delivery_notes'] ?? null,
                'estimated_delivery_at' => $validated['estimated_delivery_at'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->logActivity($order, $user, 'dispatched', 'Order dispatched for delivery');
        });

        $this->notifyOrderUpdate($order, 'dispatched');

        return back()->with('success', 'Order #' . $order->order_number . ' has been dispatched. Stock adjusted.');
    }

    public function deliverOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if ($order->status !== OrderStatus::DISPATCHED) {
            return back()->with('error', 'Only dispatched orders can be marked as delivered.');
        }

        $order->update(['status' => OrderStatus::DELIVERED]);
        $this->logActivity($order, $user, 'delivered', 'Order delivered');

        // Update delivery record
        $delivery = $order->delivery;
        if ($delivery) {
            $delivery->update([
                'status' => 'delivered',
                'actual_delivery_at' => now(),
            ]);
        }

        $this->notifyOrderUpdate($order, 'delivered');

        return back()->with('success', 'Order #' . $order->order_number . ' marked as delivered.');
    }

    public function completeOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if ($order->status !== OrderStatus::DELIVERED) {
            return back()->with('error', 'Only delivered orders can be completed.');
        }

        $order->update(['status' => OrderStatus::COMPLETED]);
        $this->logActivity($order, $user, 'completed', 'Order completed');

        $this->notifyOrderUpdate($order, 'completed');

        return back()->with('success', 'Order #' . $order->order_number . ' has been completed.');
    }

    public function cancelOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::ACCEPTED])) {
            return back()->with('error', 'Only pending or accepted orders can be cancelled.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $order->update([
            'status' => OrderStatus::CANCELLED,
            'notes' => $order->notes ? $order->notes . "\nCancellation reason: " . ($validated['reason'] ?? 'No reason provided') : 'Cancellation reason: ' . ($validated['reason'] ?? 'No reason provided'),
        ]);

        $this->logActivity($order, $user, 'cancelled', $validated['reason'] ?? 'Order cancelled');

        $this->notifyOrderUpdate($order, 'cancelled', $validated['reason'] ?? null);

        return back()->with('success', 'Order #' . $order->order_number . ' has been cancelled.');
    }

    public function returnOrder(Request $request, Order $order): RedirectResponse
    {
        $user = $this->authorizeOrderAccess($request, $order);
        if (!in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED])) {
            return back()->with('error', 'Only delivered or completed orders can be returned.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($order, $user, $validated) {
            $order->update(['status' => OrderStatus::RETURNED]);

            // Restore stock for returned items
            $ledger = app(StockLedgerService::class);
            foreach ($order->items as $item) {
                if (!$item->product_id) continue;

                $stockLoc = StockLocation::where('locationable_type', \App\Models\Store::class)
                    ->where('locationable_id', $order->store_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if ($stockLoc) {
                    $ledger->recordAddition(
                        $stockLoc,
                        (int) $item->quantity,
                        $order,
                        $user,
                        'Return — Order #' . $order->order_number
                    );
                }
            }

            // Update delivery record
            $delivery = $order->delivery;
            if ($delivery) {
                $delivery->update([
                    'status' => 'returned',
                    'return_reason' => $validated['reason'] ?? null,
                ]);
            }

            $this->logActivity($order, $user, 'returned', $validated['reason'] ?? 'Order returned');
        });

        $this->notifyOrderUpdate($order, 'returned', $validated['reason'] ?? null);

        return back()->with('success', 'Order #' . $order->order_number . ' has been returned. Stock restored.');
    }

    protected function authorizeOrderAccess(Request $request, Order $order): User
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        return $user;
    }

    protected function logActivity(Order $order, User $user, string $action, string $description): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'business_id' => $order->business_id,
            'action' => $action,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ]);
    }

    protected function notifyOrderUpdate(Order $order, string $newStatus, ?string $reason = null): void
    {
        $customer = $order->customer;
        $store = $order->store;
        $storeOwner = $store?->user;

        try {
            // Email to customer
            if ($customer && $customer->email) {
                \Mail::to($customer->email)->queue(
                    new \App\Mail\OrderStatusUpdatedMail($order, $order->status->value, $newStatus)
                );
            }

            // Email to store owner
            if ($storeOwner && $storeOwner->email && (!$customer || $storeOwner->email !== $customer->email)) {
                \Mail::to($storeOwner->email)->queue(
                    new \App\Mail\OrderStatusUpdatedMail($order, $order->status->value, $newStatus)
                );
            }

            // Email to platform admin
            $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
            if ($adminEmail && (!$customer || $adminEmail !== $customer->email) && (!$storeOwner || $adminEmail !== $storeOwner->email)) {
                \Mail::to($adminEmail)->queue(
                    new \App\Mail\OrderStatusUpdatedMail($order, $order->status->value, $newStatus)
                );
            }

            Log::info('order_status_updated_email_sent', [
                'order_id' => $order->id,
                'new_status' => $newStatus,
                'customer_email' => $customer?->email,
            ]);
        } catch (\Exception $e) {
            Log::error('order_status_email_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
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
