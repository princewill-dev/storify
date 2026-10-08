<?php

namespace App\Services\Payments\Data;

final class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $refundId = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function ok(?string $refundId = null, ?string $status = null, array $raw = []): self
    {
        return new self(true, $refundId, $status, null, $raw);
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(false, null, null, $message, $raw);
    }
}
