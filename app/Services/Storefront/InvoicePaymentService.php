<?php

namespace App\Services\Storefront;

use App\Enums\InitializationMode;
use App\Enums\InvoiceStatus;
use App\Enums\ProviderFeeBearer;
use App\Enums\TransactionStatus;
use App\Jobs\SettlePayouts;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PaymentPricing;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\Naira;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\CallbackUrl;
use App\Support\Payments\PaymentWebhookUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invoice payment through the same centralized collection as checkout.
 *
 * An invoice used to be charged by hand through Paystack, with the store's keys
 * read from a column that never existed and its balance credited directly. It
 * now resolves the store's connection like every other payment surface and
 * settles exactly the way a storefront sale does: our commission is taken on
 * the charge, a managed sale is recorded in the custody wallet and handed to
 * the payout job, and an own-keys sale credits the store's balance. The money
 * rules — conversion, the pricing block, the underpaid floor, the net — live in
 * {@see PaymentPricing}, shared with {@see CheckoutPaymentService} so the two
 * surfaces cannot disagree.
 *
 * The differences from checkout are the two that make an invoice an invoice:
 *
 *  - **The invoice's currency is authoritative.** Its amount, `amount_paid`
 *    and transaction row are all in the business's currency. The storefront
 *    refuses a store whose own currency differs, so that figure is also what
 *    the provider is asked to convert from.
 *  - **A business-level invoice has no store.** The store it is paid through is
 *    pinned onto the row under the initialize lock, so webhook scoping and
 *    reporting have one deterministic store rather than whichever storefront
 *    happened to open it.
 */
final class InvoicePaymentService
{
    public function __construct(
        private readonly CheckoutRepository $repository,
        private readonly LedgerPostingService $ledger,
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentGatewayManager $manager,
        private readonly ExchangeRateService $rates,
        private readonly PaymentPricing $pricing,
        private readonly CustodyService $custody,
    ) {}

    /**
     * The currency an invoice is denominated in.
     *
     * The business's currency, not the store's: an invoice is issued by the
     * business, and a business-level invoice has no store at all. The storefront
     * compares this against the host store's currency before it offers a payment
     * method, so a mismatch is refused rather than priced in the wrong unit.
     */
    public function currencyFor(Invoice $invoice): string
    {
        $invoice->loadMissing('business');

        return strtoupper((string) ($invoice->business?->currency ?: StoreCurrency::DEFAULT));
    }

    /**
     * Start a charge for an invoice through one provider.
     *
     * @param  array<string, mixed>  $data  the validated payload
     */
    public function initialize(
        Store $store,
        Invoice $invoice,
        float $amount,
        array $data,
        string $provider = 'paystack',
    ): PaymentInitializationOutcome {
        $connection = $this->gateways->forStore($store)[$provider] ?? null;
        $driver = $this->manager->driver($provider);

        if ($connection === null || $driver === null) {
            return PaymentInitializationOutcome::gatewayFailed(
                'That payment method is not available for this store.'
            );
        }

        $invoiceCurrency = $this->currencyFor($invoice);
        $storeCurrency = StoreCurrency::for($store);

        // The invoice was issued in one currency and the store prices in
        // another. Converting the invoice into the store's currency would change
        // what the customer owes; converting the store's figure back would be a
        // second, different rounding. Refused instead, before anything is
        // written.
        if ($invoiceCurrency !== $storeCurrency) {
            return PaymentInitializationOutcome::gatewayFailed(
                "This invoice is issued in {$invoiceCurrency}, but this store charges in {$storeCurrency}. "
                .'It cannot be paid through this storefront.'
            );
        }

        $reference = $this->pricing->reference('inv');
        $remaining = $invoice->remainingBalance();
        $storeAmountMinor = Naira::koboFromRounded($amount);
        $chargeMinor = $this->rates->convert($storeAmountMinor, $invoiceCurrency, $driver->currency());

        if ($chargeMinor === null) {
            return PaymentInitializationOutcome::gatewayFailed(
                "This invoice is priced in {$invoiceCurrency} and {$provider} collects in {$driver->currency()}. "
                ."No {$driver->currency()} exchange rate has been fetched yet, so this payment cannot be priced right now."
            );
        }

        // Below one minor unit there is nothing to charge. Refused rather than
        // rounded up — the same floor, and the same sentence, as checkout.
        if ($chargeMinor < 1) {
            $smallest = $this->rates->smallestConvertibleMinor($invoiceCurrency, $driver->currency());
            $minimum = $smallest === null ? null : number_format($smallest / 100, 2);

            return PaymentInitializationOutcome::gatewayFailed(
                $minimum === null
                    ? "This payment is too small to charge in {$driver->currency()}."
                    : "This payment is too small to charge in {$driver->currency()} — the minimum is {$minimum} {$invoiceCurrency}."
            );
        }

        $block = $this->pricing->block($invoiceCurrency, $storeAmountMinor, $chargeMinor, $driver->currency());

        $email = $invoice->recipient_email ?: $invoice->customer?->email ?: ($data['email'] ?? null);

        if (! $email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        $paymentMethodId = PaymentMethod::query()->where('code', $provider)->value('id');

        $managed = $connection->isManaged();
        $commissionKobo = $managed ? PlatformCommission::fromSettings()->on($chargeMinor) : null;

        DB::beginTransaction();

        try {
            // Locked before the write so two browser tabs, or a retry after the
            // invoice was part-paid elsewhere, cannot both price against a
            // balance that has already moved.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::PAID || $locked->status === InvoiceStatus::VOID || $locked->isFullyPaid()) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed(
                    'This invoice is no longer accepting payments.'
                );
            }

            $remainingMinor = Naira::koboFromRounded($locked->remainingBalance());

            if ($storeAmountMinor < 1 || $storeAmountMinor > $remainingMinor) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed(
                    'The amount is not valid for this invoice.'
                );
            }

            // A store-level invoice can only be paid through its own store. A
            // business-level one has no store yet, so the storefront being used
            // is pinned onto it — the webhook URL, the payout and the reporting
            // all read that column later.
            if ($locked->store_id !== null && (int) $locked->store_id !== (int) $store->id) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed(
                    'This invoice is not payable through this store.'
                );
            }

            if ($locked->store_id === null) {
                $locked->store_id = $store->id;
                $locked->save();
            }

            $transaction = Transaction::create([
                'reference' => $reference,
                'invoice_id' => $locked->id,
                'business_id' => $locked->business_id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $amount,
                // The invoice's currency, because that is the currency the
                // amount and `amount_paid` are in.
                'currency' => $invoiceCurrency,
                'status' => TransactionStatus::PENDING,
                'platform_fee_kobo' => $commissionKobo,
                'metadata' => array_merge([
                    'invoice_id' => $locked->id,
                    'invoice_number' => $locked->invoice_number,
                    'payment_token' => $locked->payment_token,
                    'is_partial' => $amount < $locked->remainingBalance(),
                    'source' => 'storefront_api',
                    'provider' => $provider,
                    'payment_mode' => $connection->paymentMode(),
                    'platform_fee_kobo' => $commissionKobo,
                ], $this->pricing->metadata($block)),
            ]);

            $result = $driver->initialize(
                new PaymentIntent(
                    reference: $reference,
                    amountMinor: $chargeMinor,
                    currency: $driver->currency(),
                    email: (string) $email,
                    // The customer's browser returns to the invoice page with
                    // the reference attached, so the page can verify without
                    // waiting on the webhook.
                    callbackUrl: CallbackUrl::withReference(
                        (string) ($data['callback_url'] ?? url('/')),
                        $reference,
                    ),
                    metadata: [
                        'invoice_id' => $locked->id,
                        'invoice_number' => $locked->invoice_number,
                        'transaction_id' => $transaction->id,
                        // Manual transfer has no API to ask where the money
                        // goes, so the store's own accounts travel with the
                        // intent.
                        'bank_accounts' => $this->bankAccountsFor($store),
                    ],
                    // Derived from the store, never from the request.
                    webhookUrl: PaymentWebhookUrl::forStore($provider, $store),
                ),
                $connection->credentials,
            );

            if (! $result->success) {
                DB::rollBack();

                return PaymentInitializationOutcome::gatewayFailed($result->message);
            }

            $transaction->update([
                'gateway_response' => $result->raw,
                'gateway_reference' => $result->providerId ?? $result->providerReference,
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'provider_reference' => $result->ownReference($reference),
                ]),
            ]);
            DB::commit();

            if ($result->mode === InitializationMode::OFFLINE) {
                return PaymentInitializationOutcome::offline(
                    $reference,
                    $amount,
                    $result->instructions,
                    $result->message,
                );
            }

            return PaymentInitializationOutcome::initialized(
                $result->redirectUrl,
                $reference,
                $amount,
                pricing: $block,
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('storefront.invoice_payment_initialize_failed', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'invoice' => $invoice->invoice_number,
            ]);

            return PaymentInitializationOutcome::failed();
        }
    }

    /**
     * Verify a payment with its provider, then settle it.
     */
    public function verify(
        Store $store,
        Invoice $invoice,
        Transaction $transaction,
        string $reference,
        string $provider = 'paystack',
    ): InvoicePaymentVerificationOutcome {
        $connection = $this->gateways->forStore($store)[$provider] ?? null;
        $driver = $this->manager->driver($provider);

        if ($connection === null || $driver === null) {
            return InvoicePaymentVerificationOutcome::failed('That payment method is not available for this store.');
        }

        if ((int) $transaction->invoice_id !== (int) $invoice->id) {
            return InvoicePaymentVerificationOutcome::failed('That reference does not belong to this invoice.');
        }

        // Already settled. The webhook and the browser return leg can both
        // arrive, and so can two refreshes; settling again would advance
        // `amount_paid` twice and queue a second receipt.
        if ($transaction->status === TransactionStatus::CONFIRMED) {
            return InvoicePaymentVerificationOutcome::verified($invoice->fresh());
        }

        $providerReference = $transaction->metadata['provider_reference'] ?? null;

        $verification = $driver->verify($providerReference ?? $reference, $connection->credentials);

        if (! $verification->isPaid()) {
            return InvoicePaymentVerificationOutcome::failed($verification->message);
        }

        if ($this->pricing->isUnderpaid($transaction, $verification)) {
            return InvoicePaymentVerificationOutcome::failed(
                'The amount received is less than the amount charged for this payment.'
            );
        }

        return InvoicePaymentVerificationOutcome::verified(
            $this->settle($transaction, $verification, $driver->code())
        );
    }

    /**
     * The one locked, idempotent state transition for an invoice payment.
     *
     * Reached by both the browser return leg and the webhook. The transaction
     * and the invoice are locked together, the status is re-read under the lock,
     * and only the call that finds it still pending does the work — so the
     * second arrival is a no-op rather than a second `amount_paid` increment,
     * a second balance credit or a second receipt.
     *
     * `$feesMinor` carries a webhook's own fee figure for providers whose
     * verification answer omits it.
     */
    public function settle(
        Transaction $transaction,
        VerificationResult $verification,
        string $providerCode,
        ?int $feesMinor = null,
    ): Invoice {
        $fees = $verification->feesMinor ?? $feesMinor;
        $confirmed = false;

        $invoice = DB::transaction(function () use ($transaction, $verification, $fees, &$confirmed): Invoice {
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = Invoice::query()
                ->whereKey($locked->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TransactionStatus::CONFIRMED) {
                return $invoice;
            }

            // Whether this sale was the platform's is a property of how it was
            // charged, read from the row rather than from a connection that may
            // have changed since. The two settlement paths must agree on it.
            $managed = $locked->isPlatformManaged();
            $commissionKobo = $locked->platformFeeKobo();
            $bearer = $managed ? ProviderFeeBearer::PLATFORM : ProviderFeeBearer::BUSINESS;
            $amountMinor = Naira::koboFromRounded($locked->amount);

            $locked->update([
                'status' => TransactionStatus::CONFIRMED->value,
                'gateway_reference' => $verification->providerId,
                'gateway_response' => $verification->raw,
                'paid_at' => now(),
                'fee_kobo' => $fees,
                'provider_fee_bearer' => $bearer,
                'platform_fee_kobo' => $managed ? $commissionKobo : null,
                'net_kobo' => Transaction::netKoboFor($amountMinor, $fees, $commissionKobo, $bearer),
            ]);

            $invoice->amount_paid = (float) $invoice->amount_paid + (float) $locked->amount;

            if ($invoice->isFullyPaid()) {
                $invoice->status = InvoiceStatus::PAID;
                $invoice->paid_at = now();
            } elseif ((float) $invoice->amount_paid > 0) {
                $invoice->status = InvoiceStatus::PARTIAL;
            }

            $invoice->save();

            // The store's own wallet, for a sale charged through its own keys.
            // **Not** for a managed sale: that money is in the business's
            // account at the provider, and a balance of ours credited on top of
            // it would be us holding money that is not ours to hold.
            if (! $managed && $invoice->store_id !== null) {
                $store = Store::query()
                    ->whereKey($invoice->store_id)
                    ->lockForUpdate()
                    ->first();

                if ($store) {
                    $balanceBefore = (int) $store->balance;
                    $store->creditBalance($amountMinor);

                    $locked->update([
                        'balance_updated_at' => now(),
                        'store_balance_before' => $balanceBefore,
                        'store_balance_after' => (int) $store->fresh()->balance,
                    ]);
                }
            }

            $confirmed = true;

            return $invoice;
        });

        // A second caller found the row already confirmed. Nothing below may
        // run: the first caller already did it, and this is exactly the race
        // the lock exists to lose quietly.
        if (! $confirmed) {
            return $invoice;
        }

        $transaction->refresh();
        $invoice->refresh();

        $this->ledger->safe(fn () => $this->ledger->postInvoice($invoice));

        if ($transaction->isPlatformManaged()) {
            $this->ledger->safe(fn () => $this->ledger->postManagedPaymentReceived(
                $transaction,
                $transaction->platformFeeKobo(),
                custody: true,
            ));

            // The driver that verified the payment is the provider that
            // collected it, which is what its settlement window is keyed on.
            $this->custody->recordCollectedSale($transaction, $providerCode);

            SettlePayouts::forSale($transaction);
        } else {
            $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction));
        }

        $this->queueReceipt($invoice, $transaction);

        Log::info('storefront.invoice_payment_settled', [
            'invoice_id' => $invoice->id,
            'transaction_id' => $transaction->id,
            'provider' => $providerCode,
            'managed' => $transaction->isPlatformManaged(),
        ]);

        return $invoice;
    }

    /**
     * The receipt, queued once — from the call that actually confirmed.
     *
     * The walk-in placeholder address is skipped, as it is everywhere a
     * recipient is resolved. A mail failure is logged and never allowed to fail
     * a payment the provider has already taken.
     */
    private function queueReceipt(Invoice $invoice, Transaction $transaction): void
    {
        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (! $to || str_contains($to, '@walkin.local')) {
            return;
        }

        try {
            Mail::to($to)->queue(new InvoicePaymentReceiptMail($invoice, $transaction));
        } catch (\Throwable $e) {
            Log::error('invoice_receipt_mail_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The store's verified bank accounts, for a provider that settles offline.
     *
     * @return array<int, array<string, string>>
     */
    private function bankAccountsFor(Store $store): array
    {
        return $this->repository->verifiedBankAccountsFor($store)
            ->map(fn ($bank): array => [
                'bank_name' => (string) $bank->bank_name,
                'account_number' => (string) $bank->account_number,
                'account_name' => (string) $bank->account_name,
            ])
            ->values()
            ->all();
    }
}
