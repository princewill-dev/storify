<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 — the store create success page: the store payload, its live URL and
 * the next step the owner's subscription state implies.
 *
 * The top-level `storefront_url` reads through StoreOnboardingResource's
 * shared rule, so it can never disagree with the URL inside the store
 * payload. `next_step` is 'dashboard' for an active subscription and
 * 'settings' otherwise, exactly as the controller's inline array mapped it.
 */
final class StoreOnboardingFinalizeResource extends JsonResource
{
    public function __construct(
        private readonly Store $store,
        private readonly int $customersCount,
        private readonly bool $subscriptionActive,
    ) {
        parent::__construct($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'store' => (new StoreOnboardingResource($this->store, $this->customersCount))->toArray($request),
            'storefront_url' => StoreOnboardingResource::storefrontUrl($this->store),
            'subscription_active' => $this->subscriptionActive,
            'next_step' => $this->subscriptionActive ? 'dashboard' : 'settings',
        ];
    }
}
