<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\InvoiceStatus;
use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\Access\StorePaymentGate;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\PaystackService;
use App\Support\Money\Naira;
use App\Support\Payments\SystemProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InvoicePaymentController extends Controller
{
    protected PaystackService $paystack;

    public function __construct(
        PaystackService $paystackService,
        protected readonly PaymentGatewayResolver $gateways,
        protected readonly StorePaymentGate $paymentGate,
    ) {
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

        // While the platform processes every charge itself there is nothing for
        // this page's Paystack button to do — the endpoint below refuses — so
        // the button goes rather than being offered and then rejected. Set here
        // rather than in the view because the flag the view already reads is the
        // honest place for it: this is the same fact, decided on the server.
        //
        // A business Storify collects for is the same case for a different
        // reason, and is checked here too because the two switches are set
        // separately: the button would otherwise be offered on a page whose
        // endpoint refuses it. One question, asked of the one method that
        // answers it, so the page and the endpoint cannot disagree.
        if ($this->enforcementProblem($invoice) !== null) {
            $storeHasPaystack = false;
        }

        // And the same for the KYC gate: a business that may not take money yet
        // gets a page that says so rather than a payment button whose endpoint
        // refuses it.
        $paymentRefusal = $this->paymentGate->refusal($store);

        if ($paymentRefusal !== null) {
            $storeHasPaystack = false;
            $storeBankAccounts = collect();
        }

        return view('storefront.pages.invoice-pay', compact('invoice', 'store', 'businessName', 'storeBankAccounts', 'storeHasPaystack', 'paymentRefusal', 'token'));
    }

    public function initialize(Request $request, string $token): JsonResponse
    {
        $invoice = Invoice::where('payment_token', $token)->firstOrFail();

        if (($refusal = $this->paymentGate->refusal($invoice->store)) !== null) {
            return response()->json(['success' => false, 'message' => $refusal], 422);
        }

        if (($problem = $this->enforcementProblem($invoice)) !== null) {
            return response()->json(['success' => false, 'message' => $problem], 409);
        }

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

        if (! $email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        // Same defect the storefront checkout had: `$gateway->pivot->api_keys`
        // is a column that has never existed, so this branch always ran false
        // and the invoice was charged through the platform's Paystack account.
        $store = $invoice->store;

        if ($store) {
            $connection = $this->gateways->connection((int) $store->business_id, 'paystack', (int) $store->id);
            $secret = $connection?->isEnabled ? $connection->credentials->get('secret_key') : null;

            if ($secret !== null && $secret !== '') {
                $this->paystack->usingGateway((object) [
                    'secret_key' => $secret,
                    'public_key' => $connection->credentials->get('public_key', '') ?? '',
                ]);
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

            if (! $result['success']) {
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

    /**
     * Why an invoice cannot be paid at all right now, or null when it can.
     *
     * This controller is the Paystack-legacy path: it charges through either the
     * business's stored keys or — when that read fails, which it always used to —
     * the platform's own keys, and it credits the store's balance rather than
     * splitting the money. Neither shape is one a system processor can allow,
     * because in both the money lands in an account the split is not defined for.
     *
     * So it is refused in words rather than quietly repointed at the selected
     * processor: rebuilding invoices on the resolver is its own piece of work,
     * and a half-done version that charges through the platform's account and
     * credits a store balance is precisely the kind of silent "charged through us,
     * unpaid" path the switch exists to close.
     *
     * **Custody refuses it for the same reason, and on its own.** A business
     * Storify collects for must have its invoice money reach Storify's balance
     * and be credited to its wallet, and this path does neither: it charges on a
     * Paystack account — the business's or the platform's, and the transaction
     * row cannot tell the two apart — and then credits `stores.balance`, which is
     * the mirror that must never be a payout source. Left open, a custody
     * business could take an invoice payment that the wallet never sees and that
     * no payout run can ever pay out: money owed to a business that neither rail
     * can deliver, which is the one outcome the whole change is built to prevent.
     *
     * Gated on custody rather than on enforcement because the two are set
     * separately: the flag can be flipped before the processor switch, and in
     * that window this path would still be open on its own.
     */
    private function enforcementProblem(?Invoice $invoice = null): ?string
    {
        if ($invoice !== null && ($invoice->business?->isUnderCustody() ?? false)) {
            return 'Storify collects this business\'s payments, so payments to this invoice must be taken '
                .'through Storify. Paying it through a provider of your own is not available.';
        }

        if (! SystemProcessor::enforced()) {
            return null;
        }

        return 'Storify processes all payments through '
            .(SystemProcessor::name() ?? SystemProcessor::stored())
            .'. Invoice payments through a provider of your own are not available while that is switched on.';
    }

    public function callback(Request $request, string $token): RedirectResponse
    {
        $invoice = Invoice::where('payment_token', $token)->firstOrFail();
        $reference = $request->query('reference');

        if (! $reference) {
            return redirect()->route('invoice.pay', ['token' => $token])->with('error', 'Invalid payment reference.');
        }

        $transaction = Transaction::where('reference', $reference)
            ->where('invoice_id', $invoice->id)
            ->first();

        if (! $transaction) {
            return redirect()->route('invoice.pay', ['token' => $token])->with('error', 'Transaction not found.');
        }

        if ($transaction->status === 'confirmed') {
            return redirect()->route('invoice.pay.show', ['token' => $token])->with('paymentSuccess', true);
        }

        $verification = $this->paystack->doubleVerifyPayment($reference);

        if (! $verification['success'] || strtolower($verification['data']['status'] ?? '') !== 'success') {
            $transaction->update([
                'status' => TransactionStatus::CANCELED->value,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'verification_failed' => true,
                    'failure_reason' => $verification['message'] ?? 'Verification failed',
                ]),
            ]);

            return redirect()->route('invoice.pay.show', ['token' => $token])->with('error', 'Payment could not be verified.');
        }

        DB::transaction(function () use ($invoice, $transaction, $verification) {
            $feesKobo = isset($verification['data']['fees']) ? (int) $verification['data']['fees'] : null;
            $amountKobo = Naira::koboFromRounded($transaction->amount);
            $commissionKobo = $transaction->platformFeeKobo();

            $transaction->update([
                'status' => TransactionStatus::CONFIRMED->value,
                'gateway_reference' => $verification['data']['id'] ?? null,
                'gateway_response' => $verification['data'],
                'fee_kobo' => $feesKobo,
                // The business's own keys again, so the business bears the
                // provider's fee — see the same line in PaystackWebhookController.
                'provider_fee_bearer' => ProviderFeeBearer::BUSINESS,
                // Via the shared rule rather than an inline subtraction — see
                // the same change in PaystackWebhookController for why.
                'net_kobo' => Transaction::netKoboFor($amountKobo, $feesKobo, $commissionKobo, ProviderFeeBearer::BUSINESS),
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
                $store = $invoice->store;
                $store->lockForUpdate();
                $balanceBefore = (int) $store->balance;
                $store->creditBalance((int) round($transaction->amount * 100));
                $transaction->update([
                    'balance_updated_at' => now(),
                    'store_balance_before' => $balanceBefore,
                    'store_balance_after' => (int) $store->fresh()->balance,
                ]);
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

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(function () use ($ledger, $invoice, $transaction) {
            $ledger->postInvoice($invoice);
            $ledger->postPaymentReceived($transaction);
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

        if (($refusal = $this->paymentGate->refusal($invoice->store)) !== null) {
            return back()->with('error', $refusal);
        }

        // The offline rail too: a slip is money paid outside the platform, so
        // there is no split to make and nothing for it to be a payment *through*
        // Storify. Refused for the same reason the storefront stops offering
        // manual transfers while a processor is set.
        if (($problem = $this->enforcementProblem($invoice)) !== null) {
            return back()->with('error', $problem);
        }

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return back()->with('error', 'This invoice is no longer accepting payments.');
        }

        $request->validate([
            'payment_slip' => 'required|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'amount' => 'required|numeric|min:1|max:'.$invoice->remainingBalance(),
            'store_bank_id' => 'nullable|exists:store_banks,id',
        ]);

        $path = $request->file('payment_slip')->store('payment-slips', 'public');

        Transaction::create([
            'reference' => 'INV-BT-'.strtoupper(Str::random(12)),
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
