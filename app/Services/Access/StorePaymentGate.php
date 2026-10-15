<?php

namespace App\Services\Access;

use App\Models\KycApplication;
use App\Models\Store;
use App\Models\User;

/**
 * Whether a store may take a customer's money yet.
 *
 * ## The gate moved here from store activation
 *
 * It used to live on `StoreLifecycleController::activate()`, which refused to
 * switch a store on until its owner's KYC was approved. That made the gate a
 * gate on *building the shop*: an owner who had not yet done their paperwork
 * could not create a storefront, add products, or see what their shop looked
 * like — and the thing being withheld had nothing to do with any of that.
 *
 * So a store is now live the moment it is created, and the same check is asked
 * at the four points where money actually changes hands. An owner can build
 * everything first and answer the paperwork when they are ready, and the
 * platform is no less protected: not one naira moves either way.
 *
 * ## One message, and it says what to do
 *
 * "This store can't process payments yet, please contact support" is the copy
 * the owner asked for, and it is deliberately the same string at every surface.
 * A customer hitting it at storefront checkout is being told something the shop
 * cannot fix for them, so it points at a person rather than at a settings page.
 *
 * ## Whose KYC
 *
 * The **business owner's**, not the acting user's — the rule the old activation
 * gate used, via `StoreLifecycleController::owner()`. A manager on a verified
 * business is not blocked by their own missing application, and a cashier on an
 * unverified one is, because the business is not yet allowed to take money
 * whichever of its staff is at the till.
 */
final class StorePaymentGate
{
    public const REFUSAL = "This store can't process payments yet, please contact support.";

    /**
     * Why this store may not take a payment, or null if it may.
     */
    public function refusal(?Store $store): ?string
    {
        if ($store === null) {
            return self::REFUSAL;
        }

        $owner = $this->owner($store);

        if ($owner === null) {
            return self::REFUSAL;
        }

        $kyc = $owner->kycApplication;

        return $kyc !== null && $kyc->status === KycApplication::STATUS_APPROVED
            ? null
            : self::REFUSAL;
    }

    public function allows(?Store $store): bool
    {
        return $this->refusal($store) === null;
    }

    /**
     * The owner the gate is asked about.
     *
     * The business's owner first, then the store's own `user_id` — the same
     * resolution the activation gate used, kept here so the two cannot drift.
     */
    private function owner(Store $store): ?User
    {
        return $store->business?->owner ?? $store->user;
    }
}
