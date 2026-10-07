<?php

namespace App\Http\Resources\Subscription;

use App\Models\Payment;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One row of the subscription page's billing history.
 *
 * `amount` is the decimal column as a JSON float while `amount_kobo` is the
 * same amount in kobo, exactly as the endpoint has always emitted them (kobo
 * via the forgiving exact parse the payload carried before).
 */
final class BillingHistoryPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => (float) $payment->amount,
            'amount_kobo' => Naira::koboFromLenient($payment->amount),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_type' => $payment->payment_type,
            'plan_name' => $payment->metadata['plan_name'] ?? null,
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $payments): array
    {
        return $payments
            ->map(fn (Payment $payment) => self::make($payment)->resolve())
            ->all();
    }
}
