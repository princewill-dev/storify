<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderRequest;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\CustomerOrderStatusUpdatedMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

class OrderController extends Controller
{
    /**
     * Display a listing of orders with filters
     */
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'store', 'vendor', 'items', 'deliveryRoute', 'bulkOrder']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        // Filter by payment status
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

        // Filter by store
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search by order number or customer email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($q) use ($search) {
                      $q->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->paginate(20)->withQueryString();

        // Get stores for filter dropdown
        $stores = Store::active()->get();

        // Get statistics
        $stats = [
            'total' => Order::count(),
        ];
        
        foreach (OrderStatus::cases() as $status) {
            $stats[strtolower($status->name)] = Order::where('status', $status->value)->count();
        }
        
        
        foreach (PaymentStatus::cases() as $status) {
            $statusValue = $status->value;
            $transactionStatus = match($statusValue) {
                'unpaid' => null,
                'pending' => \App\Enums\TransactionStatus::PENDING->value,
                'paid' => \App\Enums\TransactionStatus::CONFIRMED->value,
                'refunded' => \App\Enums\TransactionStatus::REFUNDED->value,
                'failed' => \App\Enums\TransactionStatus::CANCELED->value,
                default => $statusValue,
            };
            
            if ($statusValue === 'unpaid') {
                $stats[strtolower($status->name)] = Order::doesntHave('transactions')->count();
            } else {
                $stats[strtolower($status->name)] = Order::whereHas('transactions', fn($q) => $q->where('status', $transactionStatus))->count();
            }
        }
        
        $stats['total_revenue'] = Order::whereHas('transactions', fn($q) => $q->where('status', \App\Enums\TransactionStatus::CONFIRMED->value))->sum('total');

        return view('admin.order_management.index', compact('orders', 'stores', 'stats'))->with([
            'orderStatusBadges' => OrderStatus::badgeData(),
        ]);
    }

    /**
     * Display the specified order
     */
    public function show(Order $order)
    {
        $order->load([
            'customer',
            'store',
            'vendor',
            'items.product',
            'transactions.paymentMethod',
            'deliveryRoute'
        ]);

        // Get activity logs for this order
        $activityLogs = ActivityLog::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user')
            ->latest()
            ->get();

        return view('admin.order_management.show', compact('order', 'activityLogs'));
    }

    /**
     * Show the form for editing the specified order
     */
    public function edit(Order $order)
    {
        $order->load(['customer', 'store', 'items.product', 'deliveryRoute']);
        
        return view('admin.order_management.edit', compact('order'));
    }

    /**
     * Update the specified order
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        try {
            DB::beginTransaction();

            $oldData = $order->toArray();
            $order->update($request->validated());

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'updated',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => 'Updated order #' . $order->order_number,
                'old_values' => json_encode($oldData),
                'new_values' => json_encode($order->fresh()->toArray()),
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            Log::info('order_updated', [
                'order_id' => $order->id,
                'admin_id' => Auth::id()
            ]);

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Order updated successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('order_update_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Failed to update order: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|in:pending,accepted,processing,dispatched,delivered,completed,cancelled,returned',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $oldStatus = $order->status;
            $oldStatusValue = $oldStatus instanceof OrderStatus ? $oldStatus->value : $oldStatus;
            $newStatus = $request->status;

            $order->update([
                'status' => $newStatus,
            ]);

            // Add notes if provided
            if ($request->filled('notes')) {
                $currentNotes = $order->notes ? $order->notes . "\n\n" : '';
                $order->update([
                    'notes' => $currentNotes . '[' . now()->format('Y-m-d H:i') . '] Status changed to ' . $newStatus . ': ' . $request->notes
                ]);
            }

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'status_updated',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => "Changed order status from {$oldStatusValue} to {$newStatus}",
                'old_values' => json_encode(['status' => $oldStatusValue]),
                'new_values' => json_encode(['status' => $newStatus]),
                'ip_address' => request()->ip(),
            ]);

            // Send email notification to customer
        try {
            Mail::to($order->customer->email)->send(
                new CustomerOrderStatusUpdatedMail($order, $oldStatusValue, $newStatus)
            );

            Log::info('customer_order_status_email_sent', [
                'order_id' => $order->id,
                'customer_email' => $order->customer->email,
                'old_status' => $oldStatusValue,
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            Log::error('customer_order_status_email_failed', [
                'order_id' => $order->id,
                'customer_email' => $order->customer->email,
                'error' => $e->getMessage()
            ]);
            // Don't fail the status update if email fails
        }

        DB::commit();

            Log::info('order_status_updated', [
                'order_id' => $order->id,
                'old_status' => $oldStatusValue,
                'new_status' => $newStatus,
                'admin_id' => Auth::id()
            ]);

            return back()->with('success', "Order status updated to {$newStatus}. Customer has been notified via email.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('order_status_update_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Failed to update order status: ' . $e->getMessage());
        }
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(Request $request, Order $order)
    {
        $request->validate([
            'payment_status' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $newPaymentStatus = $request->payment_status;
            
             // Find existing transaction or create new one
            $transaction = $order->transaction()->first(); // Check accessor logic, but here direct relation
            
            // Map selected payment status to TransactionStatus
            $newStatus = match($newPaymentStatus) {
                'pending' => \App\Enums\TransactionStatus::PENDING,
                'paid' => \App\Enums\TransactionStatus::CONFIRMED,
                'refunded' => \App\Enums\TransactionStatus::REFUNDED,
                'failed' => \App\Enums\TransactionStatus::CANCELED,
                'unpaid' => null, // Special handling
                default => \App\Enums\TransactionStatus::PENDING,
            };

            // Log activity (simplified for transaction update)
             ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'payment_status_updated',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => "Changed payment status to {$newPaymentStatus}",
                'old_values' => null,
                'new_values' => json_encode(['payment_status' => $newPaymentStatus]),
                'ip_address' => request()->ip(),
            ]);

            if ($newPaymentStatus === 'unpaid') {
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

            DB::commit();

            Log::info('order_payment_status_updated', [
                'order_id' => $order->id,
                'new_payment_status' => $newPaymentStatus,
                'admin_id' => Auth::id()
            ]);

            return back()->with('success', "Payment status updated to {$newPaymentStatus}.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('order_payment_status_update_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Failed to update payment status: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified order
     */
    public function destroy(Order $order)
    {
        try {
            DB::beginTransaction();

            $orderNumber = $order->order_number;

            // Log activity before deletion
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'deleted',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => 'Deleted order #' . $orderNumber,
                'old_values' => json_encode($order->toArray()),
                'new_values' => null,
                'ip_address' => request()->ip(),
            ]);

            // Delete related records
            $order->items()->delete();
            $order->transactions()->delete();
            $order->delete();

            DB::commit();

            Log::info('order_deleted', [
                'order_id' => $order->id,
                'order_number' => $orderNumber,
                'admin_id' => Auth::id()
            ]);

            return redirect()->route('admin.orders.index')
                ->with('success', "Order #{$orderNumber} deleted successfully!");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('order_deletion_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Failed to delete order: ' . $e->getMessage());
        }
    }
}
