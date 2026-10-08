<?php

namespace App\Services\Payments\Data;

/**
 * Whether a set of credentials actually works.
 *
 * Used by the management screen's Test action, and by the pre-deploy audit that
 * checks every enabled connection still authenticates before the resolver
 * starts routing real money through it.
 */
final class ConnectionResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $raw = [],
    ) {}

    public static function ok(string $message = 'Connection successful.'): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(false, $message, $raw);
    }
}
