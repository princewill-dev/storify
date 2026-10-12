<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\CheckoutPaymentMethodResource;
use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
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
 * `cash` is always first and always present *unless the platform is processing
 * every payment itself*: it is money in hand, it needs no provider, and a till
 * that cannot record a cash sale is not a till — but a cash sale is also money
 * Storify has no sight of and no split of, which is exactly what a system
 * processor rules out. So the rule stands while the switch is off and the till
 * loses the option, with the reason, while it is on.
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
        $enforced = SystemProcessor::stored();

        $methods = [];

        // `cash` is the POS's own method, not a gateway — it is never in the
        // provider catalogue, so it is prepended here rather than resolved. It
        // is taken in the store's own currency and never converted.
        if ($enforced === null) {
            $methods[] = [
                'code' => 'cash',
                'name' => 'Cash',
                'mode' => 'offline',
                'requires_bank_account' => false,
                'currency' => $storeCurrency,
                'conversion' => null,
            ];
        }

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
                // The same sentence the storefront shows, from the same method,
                // for the same state: a till whose list is down to nothing has to
                // be able to tell the cashier whether to send the customer to the
                // bank or to call support. Null in the ordinary case — see
                // {@see PaymentGatewayResolver::storeUnavailableReason()}.
                'unavailable_reason' => $methods === [] ? $this->gateways->storeUnavailableReason($store) : null,
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
