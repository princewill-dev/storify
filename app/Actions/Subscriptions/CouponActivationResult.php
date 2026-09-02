<?php

namespace App\Actions\Subscriptions;

use App\Models\Coupon;
use App\Models\Subscription;

final readonly class CouponActivationResult
{
    public function __construct(
        public Subscription $subscription,
        public Coupon $coupon,
        public bool $couponExhausted,
    ) {}
}
