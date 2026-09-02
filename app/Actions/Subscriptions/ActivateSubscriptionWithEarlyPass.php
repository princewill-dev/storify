<?php

namespace App\Actions\Subscriptions;

use App\Models\Business;
use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ActivateSubscriptionWithEarlyPass
{
    public function execute(User $user, EarlyPass $earlyPass, SubscriptionPlan $plan): Subscription
    {
        return DB::transaction(function () use ($user, $earlyPass, $plan): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedPass = EarlyPass::query()->lockForUpdate()->findOrFail($earlyPass->id);

            if (! $lockedUser->business_id) {
                throw new DomainException('Complete business setup before using an early pass.');
            }

            Business::query()->lockForUpdate()->findOrFail($lockedUser->business_id);

            if (! $lockedPass->isAvailable()) {
                throw new DomainException('This code is not valid or has reached its usage limit.');
            }

            if (EarlyPassUsage::query()
                ->where('early_pass_id', $lockedPass->id)
                ->where('user_id', $lockedUser->id)
                ->exists()) {
                throw new DomainException('You have already used this code.');
            }

            if (Subscription::query()->where('business_id', $lockedUser->business_id)->active()->exists()) {
                throw new DomainException('You already have an active subscription.');
            }

            $subscription = Subscription::create([
                'user_id' => $lockedUser->id,
                'business_id' => $lockedUser->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => now()->addYear(),
                'metadata' => [
                    'early_pass_id' => $lockedPass->id,
                    'early_pass_code' => $lockedPass->code,
                    'activated_at' => now()->toDateTimeString(),
                    'payment_skipped' => true,
                ],
            ]);

            $lockedUser->update(['trial_ends_at' => null, 'status' => 'active', 'is_verified' => true]);
            Store::query()
                ->where('business_id', $lockedUser->business_id)
                ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
                ->update(['status' => Store::STATUS_ACTIVE]);

            $storeId = Store::query()->where('business_id', $lockedUser->business_id)->value('id');
            EarlyPassUsage::create([
                'early_pass_id' => $lockedPass->id,
                'user_id' => $lockedUser->id,
                'store_id' => $storeId,
                'used_at' => now(),
            ]);

            if ($lockedPass->max_uses !== null
                && EarlyPassUsage::where('early_pass_id', $lockedPass->id)->count() >= $lockedPass->max_uses) {
                $lockedPass->update(['is_active' => false]);
            }

            return $subscription;
        });
    }
}
