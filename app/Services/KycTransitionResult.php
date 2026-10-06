<?php

namespace App\Services;

use App\Models\KycApplication;

/**
 * WS-3 (admin console) — outcome of a KYC state transition.
 *
 * Carries the refreshed application plus whether the owner notification was
 * actually queued. The reject screen builds its message from `notified`:
 * legacy always flashed "the business owner notified" while sending nothing
 * (consolidated defect §24.1), so the message must not be hard-coded.
 */
final readonly class KycTransitionResult
{
    public function __construct(
        public KycApplication $application,
        public bool $notified,
    ) {}
}
