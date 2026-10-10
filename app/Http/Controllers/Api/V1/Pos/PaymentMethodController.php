<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\CheckoutPaymentMethodResource;
use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The payment options a till may take for this store.
 *
 * The POS app used to hard-code its list in two places, and the two disagreed:
 * checkout offered `cash, paystack, transfer` while invoice payments offered
 * `cash, bank_transfer, cheque`. Neither read the store's configuration, so a
 * provider a business connected was invisible at the till.
 *
 * This is the single source those two lists collapse into. It resolves through
 * the same resolver the storefront uses, so the till and the shopfront agree on
 * what a store accepts by construction rather than by convention.
 *
 * `cash` is always first and always present: it is money in hand, it needs no
 * provider, and a till that cannot record a cash sale is not a till.
 */
final class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly ExchangeRateService $rates,
    ) {}

    public function index(Store $store): JsonResponse
    {
        $storeCurrency = StoreCurrency::for($store);

        // `cash` is the POS's own method, not a gateway — it is never in the
        // provider catalogue, so it is prepended here rather than resolved. It
        // is taken in the store's own currency and never converted.
        $methods = [[
            'code' => 'cash',
            'name' => 'Cash',
            'mode' => 'offline',
            'requires_bank_account' => false,
            'currency' => $storeCurrency,
            'conversion' => null,
        ]];

        foreach ($this->gateways->forStore($store) as $code => $connection) {
            $definition = PaymentGatewayRegistry::get($code);

            if ($definition === null) {
                continue;
            }

            $methods[] = [
                'code' => $code,
                'name' => $definition['name'],
                'mode' => PaymentGatewayRegistry::checkoutModeFor($code),
                // The till asks for a bank account only when the method sends
                // money to one, rather than by matching the method's name.
                'requires_bank_account' => ($definition['credential_source'] ?? 'keys') === 'bank_accounts',
                'currency' => $this->providerCurrency($definition),
                'conversion' => $this->conversionBlock($storeCurrency, $this->providerCurrency($definition)),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store_currency' => $storeCurrency,
                'payment_methods' => $methods,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function providerCurrency(array $definition): string
    {
        return (string) ($definition['currency'] ?? StoreCurrency::DEFAULT);
    }

    /**
     * The one number the till needs to show a converted figure before it starts
     * a charge, or null when the provider charges in the store's own currency.
     *
     * Mirrors {@see CheckoutPaymentMethodResource}
     * deliberately: the shopfront and the till showing different figures for the
     * same payment is exactly the class of bug the resolver exists to prevent.
     *
     * @return array{divisor: float, currency: string, rated_at: string|null}|null
     */
    private function conversionBlock(string $storeCurrency, string $providerCurrency): ?array
    {
        if (strcasecmp($storeCurrency, $providerCurrency) === 0) {
            return null;
        }

        $divisor = $this->rates->divisorFor($storeCurrency, $providerCurrency);

        if ($divisor === null) {
            return null;
        }

        return [
            'divisor' => $divisor,
            'currency' => $providerCurrency,
            'rated_at' => $this->rates->ratedAt($storeCurrency, $providerCurrency)?->toIso8601String(),
        ];
    }
}
