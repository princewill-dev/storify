<?php

namespace App\Services\Subscription;

use App\Models\SubscriptionPlan;
use Carbon\CarbonInterface;

/**
 * What a plan change did, or what it is waiting for.
 *
 * Four outcomes rather than a boolean, because a plan change genuinely has four
 * and they need four different sentences on the screen: it happened (a free
 * upgrade), it is booked for later (a downgrade), it needs money (an upgrade),
 * or it is refused (a plan that grants exactly what the current one does).
 *
 * Returning the outcome rather than throwing keeps the decision in one place —
 * {@see PlanSelectionService} — while the HTTP shapes stay in the controller,
 * which is the split the rest of this namespace uses.
 */
final readonly class PlanChangeResult
{
    /** The plan is in force now. */
    public const APPLIED = 'applied';

    /** The plan takes effect when the paid period ends. */
    public const SCHEDULED = 'scheduled';

    /** A prorated charge is recorded and has to be paid before the plan moves. */
    public const PAYMENT_REQUIRED = 'payment_required';

    /** Nothing to do — the plan asked for is not a change. */
    public const REFUSED = 'refused';

    public function __construct(
        public string $status,
        public ?SubscriptionPlan $plan = null,
        /**
         * The prorated difference in kobo, when one has to be paid. Zero for
         * every other outcome.
         */
        public int $chargeKobo = 0,
        /** When a scheduled change takes effect — the end of the paid period. */
        public ?CarbonInterface $effectiveAt = null,
        public ?string $message = null,
    ) {}

    public static function applied(SubscriptionPlan $plan): self
    {
        return new self(self::APPLIED, $plan);
    }

    public static function scheduled(SubscriptionPlan $plan, ?CarbonInterface $effectiveAt): self
    {
        return new self(self::SCHEDULED, $plan, 0, $effectiveAt);
    }

    public static function paymentRequired(SubscriptionPlan $plan, int $chargeKobo): self
    {
        return new self(self::PAYMENT_REQUIRED, $plan, $chargeKobo);
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, null, 0, null, $message);
    }

    public function needsPayment(): bool
    {
        return $this->status === self::PAYMENT_REQUIRED;
    }

    public function wasRefused(): bool
    {
        return $this->status === self::REFUSED;
    }
}
