<?php

namespace App\Services\Payments\Data;

final class RefundRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly ?string $providerId = null,
        public readonly ?string $reason = null,
    ) {}
}
