<?php

namespace App\Http\Resources\Storefront;

use App\Models\PaymentMethod;
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
 */
final class CheckoutPaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentMethod $method */
        $method = $this->resource;

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
            'mode' => PaymentGatewayRegistry::checkoutModeFor((string) $method->code),
        ];
    }
}
