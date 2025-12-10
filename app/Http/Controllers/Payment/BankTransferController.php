<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Store;
use App\Enums\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BankTransferController extends Controller
{
    /**
     * Show bank transfer payment page with bank details
     */
    public function show(Request $request, $store_slug, Order $order)
    {
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        // Get active bank accounts
        $bankAccounts = BankAccount::active()->get();

        if ($bankAccounts->isEmpty()) {
            return redirect()->back()->with('error', 'No active bank accounts available. Please contact support.');
        }

        // Get transaction
        $transaction = $order->transactions()->first();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transaction not found.');
        }

        $paymentAmount = $order->source === 'live_first' 
            ? $order->total * 0.10 
            : $order->total;

        return view('payment.bank-transfer', compact('order', 'store', 'bankAccounts', 'transaction', 'paymentAmount'));
    }

    /**
     * Confirm payment with optional payment slip
     */
    public function confirmPayment(Request $request, $store_slug, Order $order)
    {
        $validated = $request->validate([
            'payment_slip' => 'nullable|file|mimes:jpeg,png,jpg,heic,pdf|max:5120', // 5MB max
        ]);

        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $transaction = $order->transactions()->first();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transaction not found.');
        }

        // Upload payment slip if provided
        $paymentSlipPath = null;
        if ($request->hasFile('payment_slip')) {
            $paymentSlipPath = $request->file('payment_slip')->store('payment-slips', 'public');
        }

        // Update transaction status to PAID
        $transaction->update([
            'status' => TransactionStatus::PAID,
            'paid_at' => now(),
            'payment_slip' => $paymentSlipPath,
        ]);

        // Update order payment status
        $order->update([
            'payment_status' => 'paid',
        ]);

        Log::info('bank_transfer.payment_confirmed', [
            'transaction_id' => $transaction->id,
            'order_id' => $order->id,
            'payment_slip' => $paymentSlipPath ? 'uploaded' : 'not_provided',
        ]);

        // Redirect to payment confirmation page
        return redirect()->route('checkout.payment', [
            'store_slug' => $store_slug,
            'order' => $order->order_number
        ])->with('success', 'Payment confirmed successfully! Your order is being processed.');
    }
}
