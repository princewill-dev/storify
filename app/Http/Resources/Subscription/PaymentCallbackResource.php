<?php

namespace App\Http\Resources\Subscription;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The callback's response body — both a fresh activation and a replay.
 */
final class PaymentCallbackResource extends JsonResource
{
    /**
     * The body returned when the payment was already processed before the
     * callback arrived.
     *
     * @return array<string, mixed>
     */
    public static function alreadyProcessed(Payment $payment): array
    {
        return [
            'already_processed' => true,
            'message' => 'Payment already processed successfully.',
            'redirect' => '/',
            'payment' => PaymentResource::make($payment)->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource['payment'];
        /** @var bool $activated */
        $activated = $this->resource['activated'];

        return [
            'already_processed' => ! $activated,
            'message' => $activated ? 'Subscription payment successful.' : 'Payment already processed successfully.',
            'redirect' => '/',
            'payment' => PaymentResource::make($payment)->resolve($request),
            'subscription' => $payment->subscription
                ? SubscriptionResource::make($payment->subscription)->resolve($request)
                : null,
        ];
    }
}
