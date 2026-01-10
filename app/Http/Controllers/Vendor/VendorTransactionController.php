<?php

namespace App\Http\Controllers\Vendor;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorTransactionController extends Controller
{
    private function resolveVendor(Request $request, Vendor $routeVendor): ?Vendor
    {
        $vendor = $request->user('vendor');
        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return null;
        }

        return $vendor;
    }

    public function index(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $query = Transaction::with(['order.customer', 'paymentMethod'])
            ->whereHas('order', fn ($q) => $q->where('vendor_id', $vendor->id));

        if ($request->filled('reference')) {
            $query->where('reference', 'like', '%' . $request->reference . '%');
        }

        if ($request->filled('status') && in_array($request->status, TransactionStatus::values(), true)) {
            $query->where('status', $request->status);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return view('vendors.transactions.index', [
            'vendor' => $vendor,
            'transactions' => $transactions,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }

    public function show(Request $request, Vendor $routeVendor, Transaction $transaction): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$transaction->order || $transaction->order->vendor_id !== $vendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        $transaction->load(['order.customer', 'order.store', 'paymentMethod']);

        return view('vendors.transactions.show', [
            'vendor' => $vendor,
            'transaction' => $transaction,
            'statusOptions' => TransactionStatus::cases(),
        ]);
    }


    public function confirmPayment(Request $request, Vendor $routeVendor, Transaction $transaction): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$transaction->order || $transaction->order->vendor_id !== $vendor->id) {
            return redirect()->route('vendor.auth.login');
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
            $store = $transaction->order->store;
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

        // Send confirmation email to customer (queued)
        try {
            $customer = $transaction->order->customer;
            $store = $transaction->order->store;
            
            \Mail::to($customer->email)->queue(
                new \App\Mail\PaymentConfirmedMail($transaction, $transaction->order, $customer, $store)
            );
            
            \Log::info('payment_confirmed_email_sent', [
                'transaction_id' => $transaction->id,
                'customer_email' => $customer->email,
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

    public function rejectPayment(Request $request, Vendor $routeVendor, Transaction $transaction): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$transaction->order || $transaction->order->vendor_id !== $vendor->id) {
            return redirect()->route('vendor.auth.login');
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
        $metadata['rejected_by'] = $vendor->id;

        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => $metadata,
        ]);

        \Log::info('payment_rejected', [
            'transaction_id' => $transaction->id,
            'reason' => $reason,
            'vendor_id' => $vendor->id,
        ]);

        // Send rejection email to customer (queued)
        try {
            $customer = $transaction->order->customer;
            $store = $transaction->order->store;
            
            \Mail::to($customer->email)->queue(
                new \App\Mail\PaymentRejectedMail($transaction, $transaction->order, $customer, $store, $reason)
            );
            
            \Log::info('payment_rejected_email_sent', [
                'transaction_id' => $transaction->id,
                'customer_email' => $customer->email,
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

    public function refundPayment(Request $request, Vendor $routeVendor, Transaction $transaction): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$transaction->order || $transaction->order->vendor_id !== $vendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        // Validate transaction is confirmed
        if ($transaction->status !== TransactionStatus::CONFIRMED) {
            return back()->with('error', 'Only confirmed transactions can be refunded.');
        }

        // Check order status - cannot refund delivered/completed orders
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
            \DB::transaction(function () use ($transaction, $reason, $vendor) {
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
                    $metadata['refunded_by'] = $vendor->id;
                    
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
