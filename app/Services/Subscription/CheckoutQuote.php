<?php

namespace App\Services\Subscription;

use App\Models\Coupon;
use App\Models\SubscriptionPlan;

/**
 * The plan, coupon and integer-kobo amounts for a checkout summary or
 * initialization. A null plan means no plan could be resolved; a non-null
 * couponError means the requested coupon was refused.
 */
final readonly class CheckoutQuote
{
    public function __construct(
        public ?SubscriptionPlan $plan,
        public ?Coupon $coupon,
        public int $planAmountKobo,
        public int $discountKobo,
        public int $totalKobo,
        public ?string $couponError,
    ) {}
}
