<?php

namespace App\Http\Controllers\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{

    protected function forBusiness($query, $user): void
    {
        if (!$user->business_id) {
            return;
        }
        $query->where('business_id', $user->business_id);
    }

    protected function userOwnsTransaction(User $user, Transaction $transaction): bool
    {
        if ($transaction->order) {
            if ($user->isRestrictedStaff()) {
                $storeIds = $user->assignedStores()->pluck('id')->toArray();
                return in_array($transaction->order->store_id, $storeIds);
            }
            return $transaction->order->business_id === $user->business_id;
        }

        if ($transaction->invoice) {
            return $transaction->invoice->business_id === $user->business_id;
        }

        return $user->business_id === null;
    }


    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $query = Transaction::with(['order.customer', 'order.store', 'invoice.store', 'paymentMethod']);
        $this->forBusiness($query, $user);
        if ($user->isRestrictedStaff()) {
            $query->where(function ($q) use ($user) {
                $storeIds = $user->assignedStores()->pluck('id')->toArray();
                $q->whereHas('order', fn($o) => $o->whereIn('store_id', $storeIds))
                  ->orWhereHas('invoice', fn($i) => $i->whereIn('store_id', $storeIds));
            });
        }

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->reference . '%');
        }

        if ($request->filled('status') && in_array($request->status, TransactionStatus::values(), true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('order', fn($o) => $o->where('store_id', $request->store_id))
                  ->orWhereHas('invoice', fn($i) => $i->where('store_id', $request->store_id));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        // Get stores for the filter dropdown
        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->orderBy('name')->get();

        $activeFilters = $request->only(['reference', 'status', 'store_id', 'date_from', 'date_to']);

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Transactions']];
        return view('management.transactions.index', [
            'user' => $user,
            'transactions' => $transactions,
            'statusOptions' => TransactionStatus::cases(),
            'stores' => $stores,
            'activeFilters' => $activeFilters,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function show(Request $request, Transaction $transaction): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->userOwnsTransaction($user, $transaction)) {
            abort(403, 'You do not have access to this transaction.');
        }

        $transaction->load(['order.customer', 'order.store', 'order.items', 'order.staff', 'invoice.store', 'invoice.items', 'paymentMethod', 'storeBank']);

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Transactions', 'url' => route('management.transactions.index')], ['label' => $transaction->reference]];
        return view('management.transactions.show', [
            'user' => $user,
            'transaction' => $transaction,
            'statusOptions' => TransactionStatus::cases(),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }


    public function confirmPayment(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->userOwnsTransaction($user, $transaction)) {
            abort(403, 'You do not have access to this transaction.');
        }

        // Validate transaction is pending
        if ($transaction->status !== TransactionStatus::PENDING) {
            return back()->with('error', 'Only pending transactions can be confirmed.');
        }

        $oldStatus = $transaction->status;
        
        // Use transaction for atomic operation
        \DB::transaction(function () use ($transaction, $oldStatus) {
            // Update transaction status to CONFIRMED
            $transaction->update(['status' => TransactionStatus::CONFIRMED->value]);
            
            // Credit the store balance
            $store = $transaction->order?->store ?? $transaction->invoice?->store;
            if (!$store) {
                throw new \RuntimeException('Transaction has no associated store.');
            }
            $amountInKobo = (int) ($transaction->amount * 100);
            
            // Lock and record balance before
            $store->lockForUpdate();
            $balanceBefore = $store->balance;
            
            // Credit the store balance atomically
            try {
                $store->creditBalance($amountInKobo);
                
                // Record audit trail
                $transaction->update([
                    'balance_updated_at' => now(),
                    'store_balance_before' => $balanceBefore,
                    'store_balance_after' => $store->fresh()->balance,
                ]);

                // Update order amount_paid for split payment support
                if ($transaction->order) {
                    $order = $transaction->order;
                    $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                    if ($order->isFullyPaid() && $order->status->value === 'pending') {
                        $order->status = \App\Enums\OrderStatus::ACCEPTED;
                    }
                    $order->save();
                }
                
                \Log::info('payment_confirmed', [
                    'transaction_id' => $transaction->id,
                    'store_id' => $store->id,
                    'amount_kobo' => $amountInKobo,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $store->fresh()->balance,
                ]);
            } catch (\Exception $e) {
                \Log::error('store_balance_credit_failed', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });

        // Send confirmation emails (queued)
        try {
            $customer = $transaction->order->customer;
            $store = $transaction->order->store;

            // Email to customer
            \Mail::to($customer->email)->queue(
                new \App\Mail\PaymentConfirmedMail($transaction, $transaction->order, $customer, $store)
            );

            // Email to store owner / assigned user
            $storeOwner = $store->user;
            if ($storeOwner && $storeOwner->email && $storeOwner->email !== $customer->email) {
                \Mail::to($storeOwner->email)->queue(
                    new \App\Mail\PaymentConfirmedMail($transaction, $transaction->order, $storeOwner, $store)
                );
            }

            // Email to platform admin
            $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
            if ($adminEmail && $adminEmail !== $customer->email && (!$storeOwner || $adminEmail !== $storeOwner->email)) {
                \Mail::to($adminEmail)->queue(
                    new \App\Mail\PaymentConfirmedMail($transaction, $transaction->order, null, $store)
                );
            }

            \Log::info('payment_confirmed_email_sent', [
                'transaction_id' => $transaction->id,
                'customer_email' => $customer->email,
                'store_owner_email' => $storeOwner->email ?? null,
                'admin_email' => $adminEmail ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_confirmed_email_failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the entire operation if email fails
        }

        return back()->with('success', 'Payment confirmed! Store balance has been credited and customer has been notified.');
    }

    public function rejectPayment(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->userOwnsTransaction($user, $transaction)) {
            abort(403, 'You do not have access to this transaction.');
        }

        // Validate transaction is pending
        if ($transaction->status !== TransactionStatus::PENDING) {
            return back()->with('error', 'Only pending transactions can be rejected.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $reason = $validated['reason'] ?? null;

        // Update transaction status to CANCELED and store reason
        $metadata = $transaction->metadata ?? [];
        $metadata['rejection_reason'] = $reason;
        $metadata['rejected_at'] = now()->toDateTimeString();
        $metadata['rejected_by'] = $user->id;

        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => $metadata,
        ]);

        if ($transaction->invoice) {
            return back()->with('success', 'Invoice payment rejected.');
        }

        // Send rejection emails (queued)
        try {
            $customer = $transaction->order->customer;
            $store = $transaction->order->store;

            // Email to customer
            \Mail::to($customer->email)->queue(
                new \App\Mail\PaymentRejectedMail($transaction, $transaction->order, $customer, $store, $reason)
            );

            // Email to store owner / assigned user
            $storeOwner = $store->user;
            if ($storeOwner && $storeOwner->email && $storeOwner->email !== $customer->email) {
                \Mail::to($storeOwner->email)->queue(
                    new \App\Mail\PaymentRejectedMail($transaction, $transaction->order, $storeOwner, $store, $reason)
                );
            }

            // Email to platform admin
            $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
            if ($adminEmail && $adminEmail !== $customer->email && (!$storeOwner || $adminEmail !== $storeOwner->email)) {
                \Mail::to($adminEmail)->queue(
                    new \App\Mail\PaymentRejectedMail($transaction, $transaction->order, null, $store, $reason)
                );
            }

            \Log::info('payment_rejected_email_sent', [
                'transaction_id' => $transaction->id,
                'customer_email' => $customer->email,
                'store_owner_email' => $storeOwner->email ?? null,
                'admin_email' => $adminEmail ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_rejected_email_failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the entire operation if email fails
        }

        return back()->with('success', 'Payment rejected and customer has been notified.');
    }

    public function refundPayment(Request $request, Transaction $transaction): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->userOwnsTransaction($user, $transaction)) {
            abort(403, 'You do not have access to this transaction.');
        }

        // Validate transaction is confirmed
        if ($transaction->status !== TransactionStatus::CONFIRMED) {
            return back()->with('error', 'Only confirmed transactions can be refunded.');
        }

        // Check order status - cannot refund delivered/completed orders
        if ($transaction->invoice) {
            return back()->with('error', 'Refunds are not supported for invoice payments.');
        }

        $order = $transaction->order;
        if (in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED])) {
            return back()->with('error', 'Cannot refund delivered/completed orders. Please mark the order as "Returned" first.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $reason = $validated['reason'];

        // Use transaction for atomic operation
        try {
            \DB::transaction(function () use ($transaction, $reason, $user) {
                $store = $transaction->order->store;
                $amountInKobo = (int) ($transaction->amount * 100);
                
                // Lock and record balance before
                $store->lockForUpdate();
                $balanceBefore = $store->balance;
                
                // Debit the store balance (will throw exception if insufficient)
                try {
                    $store->debitBalance($amountInKobo);
                    
                    // Update transaction with refund info
                    $metadata = $transaction->metadata ?? [];
                    $metadata['refund_reason'] = $reason;
                    $metadata['refunded_at'] = now()->toDateTimeString();
                    $metadata['refunded_by'] = $user->id;
                    
                    $transaction->update([
                        'status' => TransactionStatus::REFUNDED->value,
                        'metadata' => $metadata,
                        'balance_updated_at' => now(),
                        'store_balance_before' => $balanceBefore,
                        'store_balance_after' => $store->fresh()->balance,
                    ]);
                    
                    \Log::info('payment_refunded', [
                        'transaction_id' => $transaction->id,
                        'store_id' => $store->id,
                        'amount_kobo' => $amountInKobo,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $store->fresh()->balance,
                        'reason' => $reason,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('store_balance_debit_failed', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            });
        } catch (\Exception $e) {
            // Handle insufficient balance or other errors
            if (str_contains($e->getMessage(), 'Insufficient balance')) {
                return back()->with('error', 'Insufficient store balance to process refund. Current balance: ₦' . number_format($transaction->order->store->getBalanceInNaira(), 2));
            }
            return back()->with('error', 'Failed to process refund: ' . $e->getMessage());
        }

        // Send refund email to customer (queued)
        try {
            $customer = $transaction->order->customer;
            $store = $transaction->order->store;
            
            \Mail::to($customer->email)->queue(
                new \App\Mail\RefundProcessedMail($transaction, $transaction->order, $customer, $store, $reason)
            );
            
            \Log::info('refund_email_sent', [
                'transaction_id' => $transaction->id,
                'customer_email' => $customer->email,
            ]);
        } catch (\Exception $e) {
            \Log::error('refund_email_failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the entire operation if email fails
        }

        return back()->with('success', 'Refund processed successfully! Store balance has been debited and customer has been notified.');
    }
}
