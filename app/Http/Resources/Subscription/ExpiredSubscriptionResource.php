<?php

namespace App\Http\Resources\Subscription;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lapsed-subscription block beside the WS-08 checkout summary.
 */
final class ExpiredSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Subscription $subscription */
        $subscription = $this->resource;

        return [
            'id' => $subscription->id,
            'plan_name' => $subscription->subscriptionPlan?->name,
            'expired_at' => $subscription->expires_at?->toISOString(),
        ];
    }
}
