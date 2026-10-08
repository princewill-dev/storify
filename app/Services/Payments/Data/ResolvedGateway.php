<?php

namespace App\Services\Payments\Data;

/**
 * One provider, as it resolves for one store.
 *
 * `source` is what the management screen badges — `store` when a per-store row
 * decides it, `business` when it inherits, `none` when nothing is set. It
 * mirrors the Plugins screen exactly, because a shopkeeper should not have to
 * learn two vocabularies for the same idea.
 */
final class ResolvedGateway
{
    public function __construct(
        public readonly string $code,
        public readonly bool $isEnabled,
        public readonly GatewayCredentials $credentials,
        public readonly string $source,
        public readonly ?int $connectionId = null,
        public readonly bool $businessConnected = false,
        public readonly bool $storeOverrides = false,
        /**
         * Whether a connection row exists and is switched on, *ignoring* the
         * platform gate.
         *
         * Distinct from `isEnabled`, which is false both when a business has
         * not connected this provider and when an admin has switched it off
         * platform-wide. The two read the same to a boolean but mean opposite
         * things to the screen: one should be hidden, the other explained —
         * a business must not find a connection they made simply gone.
         */
        public readonly bool $hasActiveConnection = false,
    ) {}

    public function isUsable(): bool
    {
        return $this->isEnabled && ! $this->credentials->isEmpty();
    }
}
