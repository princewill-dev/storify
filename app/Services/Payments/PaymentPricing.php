<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Storefront\CheckoutPaymentService;
use App\Support\Money\ExchangeRateService;
use Illuminate\Support\Facades\Log;

/**
 * The arithmetic and small contracts shared by every surface that takes a
 * payment: checkout and invoices.
 *
 * These four pieces used to be private methods on
 * {@see CheckoutPaymentService}. Invoices now run
 * through the same centralized collection, and a second copy of the pricing
 * block or the underpaid floor would be two places for the same rule to drift —
 * a difference that shows up as an under- or over-charge rather than as an
 * error. Checkout's behaviour is unchanged: every method here is the moved
 * implementation, not a rewrite.
 */
final class PaymentPricing
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * The reference a new transaction is known by.
     *
     * Random rather than clock-derived: the value is shown to the customer and
     * handed to the provider, and a timestamp would let anyone who knew roughly
     * when a payment was opened write it down. Sixty-four bits from
     * `random_bytes` against a unique column is not a collision that will
     * happen.
     */
    public function reference(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(8));
    }

    /**
     * The record of what the provider was asked to charge.
     *
     * **Empty when the two currencies match**, which is six providers out of
     * seven. That is what keeps a naira transaction byte-identical to the one
     * written before conversion existed, so the settlement floor keyed on this
     * block's presence cannot change the behaviour of any payment that does not
     * involve a conversion.
     *
     * Returned *unwrapped*: {@see metadata()} puts it under
     * `metadata.pricing`, and the controllers echo it as `data.pricing` as it
     * is.
     *
     * @return array<string, mixed>
     */
    public function block(string $storeCurrency, int $storeAmountMinor, int $chargeMinor, string $chargeCurrency): array
    {
        if ($storeCurrency === $chargeCurrency) {
            return [];
        }

        return [
            'store_amount_minor' => $storeAmountMinor,
            'store_currency' => $storeCurrency,
            'charge_amount_minor' => $chargeMinor,
            'charge_currency' => $chargeCurrency,
            // Store-minor units per charge-minor unit — for a naira store
            // paying in USD, the familiar "₦1,331 to the dollar".
            'rate' => $this->rates->divisorFor($storeCurrency, $chargeCurrency),
            'rated_at' => $this->rates->ratedAt($storeCurrency, $chargeCurrency)?->toIso8601String(),
        ];
    }

    /**
     * The same block as it is stored: under `metadata.pricing`, or absent
     * entirely when there was nothing to convert.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, array<string, mixed>>
     */
    public function metadata(array $block): array
    {
        return $block === [] ? [] : ['pricing' => $block];
    }

    /**
     * Whether the provider reports less than this payment was asked to charge.
     *
     * Only asks the question when a converted charge was recorded. A row with
     * no `pricing` — every naira transaction, and every row predating
     * conversion — answers "no" without a comparison, so its settlement is
     * exactly what it was before.
     *
     * Deliberately `<` and not `!=`: a provider reporting *more* is a customer
     * having overpaid through FX movement between checkout and settlement, and
     * refusing that would reject real money.
     */
    public function isUnderpaid(Transaction $transaction, VerificationResult $verification): bool
    {
        $expected = $transaction->chargeAmountMinor();

        if ($expected === null || $verification->amountMinor >= $expected) {
            return false;
        }

        Log::warning('payments.settlement.underpaid', [
            'reference' => $transaction->reference,
            'expected_minor' => $expected,
            'reported_minor' => $verification->amountMinor,
            'charge_currency' => $transaction->metadata['pricing']['charge_currency'] ?? null,
        ]);

        return true;
    }
}
