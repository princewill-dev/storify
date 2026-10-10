<?php

namespace App\Http\Resources\Storefront;

use App\Models\PaymentMethod;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment method option in the storefront checkout — the controller's
 * inline map moved verbatim: field names, order and types unchanged.
 *
 * The caller passes the collection chosen by
 * CheckoutRepository::paymentMethodsFor (the store's own active methods, or
 * the platform-wide fallback); this class issues no queries of its own.
 *
 * ## Conversion
 *
 * A method that charges in another currency carries a `conversion` block so the
 * customer can be shown the converted figure *before* they pay. It holds one
 * `divisor` — divide the store's minor amount by it and round, exactly as the
 * server does — rather than a rates map, because the client's arithmetic has to
 * mirror `ExchangeRateService::convert()` and a map invites getting it wrong.
 *
 * The store's currency is passed in rather than looked up: the caller already
 * has the store, and reaching for it here would be a second query per method in
 * the list.
 */
final class CheckoutPaymentMethodResource extends JsonResource
{
    /**
     * @param  string  $storeCurrency  what the store prices in, from
     *                                 {@see StoreCurrency::for()}
     */
    public function __construct(PaymentMethod $method, private readonly string $storeCurrency)
    {
        parent::__construct($method);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentMethod $method */
        $method = $this->resource;

        $code = (string) $method->code;
        $providerCurrency = (string) (PaymentGatewayRegistry::get($code)['currency'] ?? StoreCurrency::DEFAULT);

        return [
            'id' => $method->id,
            'name' => $method->name,
            'code' => $method->code,
            'type' => $method->type,
            'description' => $method->description,
            // How the customer finishes paying: `redirect` to a hosted
            // checkout, or `offline` for instructions. The storefront branches
            // on this instead of recognising provider names — it previously
            // treated any gateway as Paystack and sent everything else to the
            // bank-transfer page, which is why a second gateway could not work.
            'mode' => PaymentGatewayRegistry::checkoutModeFor($code),
            // What this provider will actually charge in, which is not
            // necessarily what the store prices in.
            'currency' => $providerCurrency,
            'conversion' => $this->conversionBlock($providerCurrency),
        ];
    }

    /**
     * How to show this method's figure to a customer, or null when it needs no
     * conversion.
     *
     * Null whenever the provider charges in the store's own currency — the
     * common case, six providers of seven — and null when no rate has been
     * fetched, in which case the method would not have been offered at all.
     *
     * @return array{divisor: float, currency: string, rated_at: string|null}|null
     */
    private function conversionBlock(string $providerCurrency): ?array
    {
        if (strcasecmp($providerCurrency, $this->storeCurrency) === 0) {
            return null;
        }

        $rates = app(ExchangeRateService::class);
        $divisor = $rates->divisorFor($this->storeCurrency, $providerCurrency);

        if ($divisor === null) {
            return null;
        }

        return [
            'divisor' => $divisor,
            'currency' => $providerCurrency,
            'rated_at' => $rates->ratedAt($this->storeCurrency, $providerCurrency)?->toIso8601String(),
        ];
    }
}
