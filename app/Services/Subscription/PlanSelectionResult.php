<?php

namespace App\Services\Subscription;

use Carbon\Carbon;

/**
 * What a plan selection did: whether this call started the platform trial,
 * when the trial now ends (null when no trial applies) and the trial length
 * the response message quotes.
 */
final readonly class PlanSelectionResult
{
    public function __construct(
        public bool $trialStarted,
        public ?Carbon $trialEndsAt,
        public int $trialDays,
    ) {}
}
