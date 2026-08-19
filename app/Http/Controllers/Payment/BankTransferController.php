<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Store;
use App\Models\User;
use App\Enums\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewOrderAdminMail;
use App\Mail\OrderReceivedMail;
use App\Mail\VendorOrderNotificationMail;

class BankTransferController extends Controller
{
    private function routeName(string $name): string
    {
        return app()->environment('local') ? 'local.' . $name : $name;
    }

    /**
     * Show bank transfer payment page with bank details
     */
    public function show(Request $request, $store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        // Get business bank accounts (store assignment via store_payment_method pivot)
        $bankAccounts = $store->assignedBanks()->where('is_verified', true)->get();

        if ($bankAccounts->isEmpty()) {
            return redirect()->back()->with('error', 'This store has no bank details configured. Please contact support.');
        }

        // Get the latest pending bank-transfer transaction (supports split payments)
        $transaction = $order->transactions()
            ->where('payment_method_id', null)
            ->where('status', TransactionStatus::PENDING)
            ->latest()
            ->first();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transaction not found.');
        }

        $paymentAmount = $transaction->amount ?: $order->remainingBalance();

        return view('storefront.pages.payment.bank-transfer', compact('order', 'store', 'bankAccounts', 'transaction', 'paymentAmount'));
    }

    /**
     * Confirm payment with optional payment slip
     */
    public function confirmPayment(Request $request, $store_subdomain, Order $order)
    {
        $validated = $request->validate([
            'payment_slip' => 'nullable|file|mimes:jpeg,png,jpg,heic,pdf|max:5120', // 5MB max
            'store_bank_id' => 'nullable|exists:store_banks,id',
        ]);

        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $transaction = $order->transactions()
            ->where('payment_method_id', null)
            ->where('status', TransactionStatus::PENDING)
            ->latest()
            ->first();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transaction not found.');
        }

        // Upload payment slip if provided
        $paymentSlipPath = null;
        if ($request->hasFile('payment_slip')) {
            $paymentSlipPath = $request->file('payment_slip')->store('payment-slips', 'public');
        }

        // Update transaction — remains PENDING until merchant confirms
        $transaction->update([
            'paid_at' => now(),
            'payment_slip' => $paymentSlipPath,
            'store_bank_id' => $request->store_bank_id,
        ]);

        Log::info('bank_transfer.payment_confirmed', [
            'transaction_id' => $transaction->id,
            'order_id' => $order->id,
            'amount' => $transaction->amount,
            'payment_slip' => $paymentSlipPath ? 'uploaded' : 'not_provided',
        ]);

        // Send email notifications
        try {
            Mail::to($order->customer->email)->send(new OrderReceivedMail($order));
            
            $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'));
            if ($adminEmail && $adminEmail !== 'admin@example.com') {
                Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
            }

            $userEmail = User::find($order->user_id)?->email;
            if ($userEmail) {
                Mail::to($userEmail)->send(new VendorOrderNotificationMail($order));
            }
        } catch (\Exception $e) {
            Log::error('payment_confirmation_email_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }

        // Return JSON response for AJAX requests
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed successfully!',
                'redirect_url' => route($this->routeName('payment.pending'), ['store_subdomain' => $store_subdomain, 'order' => $order->order_number]),
            ]);
        }

        // Redirect to pending page for regular requests
        return redirect()->route($this->routeName('payment.pending'), ['store_subdomain' => $store_subdomain, 'order' => $order->order_number]);
    }

    /**
     * Show pending payment verification page
     */
    public function pending(Request $request, $store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        return view('storefront.pages.payment.pending_payment', compact('order', 'store'));
    }

    /**
     * Show remaining balance page after partial payment
     */
    public function remaining(Request $request, $store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $order->load('transactions');
        $paymentMethods = $store->paymentMethods()->wherePivot('is_active', true)->get();
        $remaining = $order->remainingBalance();

        return view('storefront.pages.payment.payment-remaining', compact('order', 'store', 'paymentMethods', 'remaining'));
    }
}
