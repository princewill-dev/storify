<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\PaymentMethod;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class InvoicePaymentController extends Controller
{
    protected PaystackService $paystack;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystack = $paystackService;
    }

    public function show(string $token): View
    {
        $invoice = Invoice::where('payment_token', $token)->with(['items', 'store', 'store.banks', 'transactions', 'business'])->firstOrFail();

        if ($invoice->status === InvoiceStatus::DRAFT) {
            abort(404);
        }

        $store = $invoice->store;
        $businessName = $store?->name ?? $invoice->business?->name ?? config('app.name');
        $storeBankAccounts = $store?->assignedBanks()->where('is_verified', true)->get() ?? collect();

        $storeHasPaystack = $store && $store->paymentMethods()->where('code', 'paystack')->wherePivot('is_active', true)->exists();

        return view('storefront.pages.invoice-pay', compact('invoice', 'store', 'businessName', 'storeBankAccounts', 'storeHasPaystack', 'token'));
    }

    public function initialize(Request $request, string $token): JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)->firstOrFail();

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return response()->json(['success' => false, 'message' => 'This invoice is no longer accepting payments.'], 400);
        }

        $remaining = $invoice->remainingBalance();
        $amount = (float) $request->input('amount', 0);

        if ($amount <= 0 || $amount > $remaining) {
            return response()->json(['success' => false, 'message' => 'Invalid payment amount.'], 422);
        }

        $reference = $this->paystack->generateReference('INV');
        $email = $invoice->recipient_email ?: $invoice->customer?->email;

        if (!$email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        $store = $invoice->store;
        if ($store && $store->paymentMethods()->where('code', 'paystack')->wherePivot('is_active', true)->exists()) {
            $gateway = $store->paymentMethods()->where('code', 'paystack')->first();
            $keys = $gateway?->pivot?->api_keys ?? [];
            if (!empty($keys['secret_key'])) {
                $this->paystack->usingGateway((object) ['secret_key' => $keys['secret_key'], 'public_key' => $keys['public_key'] ?? '']);
            }
        }

        DB::beginTransaction();
        try {
            $paystackMethod = PaymentMethod::where('code', 'paystack')->first();

            $transaction = Transaction::create([
                'reference' => $reference,
                'invoice_id' => $invoice->id,
                'business_id' => $invoice->business_id,
                'payment_method_id' => $paystackMethod?->id,
                'amount' => $amount,
                'currency' => 'NGN',
                'status' => 'pending',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'payment_token' => $token,
                    'is_partial' => $amount < $remaining,
                ],
            ]);

            $result = $this->paystack->initializePayment([
                'email' => $email ?? config('mail.from.address', 'no-reply@storify.test'),
                'amount' => (int) ($amount * 100),
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => route('invoice.pay.callback', ['token' => $token]),
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'transaction_id' => $transaction->id,
                ],
            ]);

            if (!$result['success']) {
                DB::rollBack();
                Log::error('invoice_payment_initialize_failed', ['invoice_id' => $invoice->id, 'paystack_message' => $result['message']]);
                return response()->json(['success' => false, 'message' => $result['message'] ?? 'Payment initialization failed.'], 500);
            }

            $transaction->update(['gateway_response' => $result['data']]);
            DB::commit();

            return response()->json([
                'success' => true,
                'authorization_url' => $result['data']['authorization_url'],
                'reference' => $reference,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('invoice_payment_initialize_failed', ['error' => $e->getMessage(), 'invoice_id' => $invoice->id]);
            return response()->json(['success' => false, 'message' => 'An error occurred.'], 500);
        }
    }

    public function callback(Request $request, string $token): RedirectResponse
    {
        $invoice = Invoice::where('payment_token', $token)->firstOrFail();
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->route('invoice.pay', ['token' => $token])->with('error', 'Invalid payment reference.');
        }

        $transaction = Transaction::where('reference', $reference)
            ->where('invoice_id', $invoice->id)
            ->first();

        if (!$transaction) {
            return redirect()->route('invoice.pay', ['token' => $token])->with('error', 'Transaction not found.');
        }

        if ($transaction->status === 'confirmed') {
            return redirect()->route('invoice.pay.show', ['token' => $token])->with('paymentSuccess', true);
        }

        $verification = $this->paystack->doubleVerifyPayment($reference);

        if (!$verification['success'] || strtolower($verification['data']['status'] ?? '') !== 'success') {
            $transaction->update(['status' => 'failed', 'failure_reason' => $verification['message'] ?? 'Verification failed']);
            return redirect()->route('invoice.pay.show', ['token' => $token])->with('error', 'Payment could not be verified.');
        }

        DB::transaction(function () use ($invoice, $transaction, $verification) {
            $transaction->update([
                'status' => 'confirmed',
                'gateway_reference' => $verification['data']['id'] ?? null,
                'gateway_response' => $verification['data'],
                'paid_at' => now(),
            ]);

            $invoice->amount_paid += (float) $transaction->amount;

            if ($invoice->isFullyPaid()) {
                $invoice->status = InvoiceStatus::PAID;
                $invoice->paid_at = now();
            } elseif ($invoice->amount_paid > 0) {
                $invoice->status = InvoiceStatus::PARTIAL;
            }

            $invoice->save();

            if ($invoice->store) {
                $invoice->store->creditBalance((int) ($transaction->amount * 100));
                Log::info('invoice_payment_store_credited', [
                    'invoice_id' => $invoice->id,
                    'store_id' => $invoice->store_id,
                    'amount_kobo' => (int) ($transaction->amount * 100),
                    'amount_naira' => $transaction->amount,
                ]);
            }

            try {
                $to = $invoice->recipient_email ?: $invoice->customer?->email;
                if ($to) {
                    Mail::to($to)->queue(new InvoicePaymentReceiptMail($invoice, $transaction));
                }
            } catch (\Throwable $e) {
                Log::error('invoice_receipt_mail_failed', ['error' => $e->getMessage()]);
            }
        });

        return redirect()->route('invoice.pay.show', ['token' => $token])->with('paymentSuccess', true);
    }

    public function success(string $token): View
    {
        $invoice = Invoice::where('payment_token', $token)->with(['items', 'store', 'transactions', 'business'])->firstOrFail();
        $store = $invoice->store;
        $businessName = $store?->name ?? $invoice->business?->name ?? config('app.name');
        $storeBankAccounts = collect();
        $storeHasPaystack = false;
        return view('storefront.pages.invoice-pay', compact('invoice', 'token', 'businessName', 'storeBankAccounts', 'storeHasPaystack', 'store'))->with('paymentSuccess', true);
    }

    public function bankTransfer(Request $request, string $token): RedirectResponse
    {
        $invoice = Invoice::where('payment_token', $token)->firstOrFail();

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return back()->with('error', 'This invoice is no longer accepting payments.');
        }

        $request->validate([
            'payment_slip' => 'required|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'amount' => 'required|numeric|min:1|max:' . $invoice->remainingBalance(),
            'store_bank_id' => 'nullable|exists:store_banks,id',
        ]);

        $path = $request->file('payment_slip')->store('payment-slips', 'public');

        Transaction::create([
            'reference' => 'INV-BT-' . strtoupper(\Illuminate\Support\Str::random(12)),
            'invoice_id' => $invoice->id,
            'business_id' => $invoice->business_id,
            'amount' => $request->amount,
            'currency' => 'NGN',
            'status' => 'pending',
            'payment_slip' => $path,
            'store_bank_id' => $request->store_bank_id,
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'payment_method' => 'bank_transfer',
            ],
        ]);

        return redirect()->route('invoice.pay', ['token' => $token])
            ->with('success', 'Payment slip uploaded. Your payment will be confirmed shortly.');
    }
}
