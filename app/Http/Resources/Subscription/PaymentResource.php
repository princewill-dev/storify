<?php

namespace App\Http\Resources\Subscription;

use App\Models\Payment;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A billing-history / callback payment row, including its subscription.
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        $displayStatus = match ($payment->status) {
            Payment::STATUS_SUCCESS => 'paid',
            Payment::STATUS_FAILED => 'failed',
            Payment::STATUS_ABANDONED => 'cancelled',
            default => 'pending',
        };

        return [
            'id' => $payment->id,
            'payment_code' => $payment->payment_code,
            'reference' => $payment->reference,
            'reference_truncated' => Str::length($payment->reference) > 24
                ? Str::substr($payment->reference, 0, 12).'…'.Str::substr($payment->reference, -8)
                : $payment->reference,
            'amount' => (string) $payment->amount,
            'amount_kobo' => Naira::koboFromStrict($payment->amount),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'display_status' => $displayStatus,
            'payment_type' => $payment->payment_type,
            'plan_name' => data_get($payment->metadata, 'plan_name'),
            'coupon_code' => data_get($payment->metadata, 'coupon_code'),
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
            'subscription' => $payment->subscription
                ? SubscriptionResource::make($payment->subscription)->resolve($request)
                : null,
        ];
    }
}
