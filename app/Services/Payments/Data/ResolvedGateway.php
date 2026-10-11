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
        /**
         * The connected account's state, when this connection is one the
         * platform processes rather than the business's own keys. Null for an
         * own-key connection and for a provider with no connection.
         */
        public readonly ?ManagedAccount $managed = null,
        /**
         * Whether the business has deliberately switched this connection off.
         *
         * Read from the row that holds the account rather than from `isEnabled`,
         * because `isEnabled` is false for several unrelated reasons — an admin
         * disabled the provider, the credentials are incomplete — and only this
         * one means *the owner did it*. The screen needs the difference: a
         * payment option missing because its owner paused it should say so, and
         * offer the switch back.
         */
        public readonly bool $paused = false,
    ) {}

    public function isUsable(): bool
    {
        return $this->isEnabled && ! $this->credentials->isEmpty();
    }

    /**
     * Whether the platform charges on this business's behalf here.
     *
     * Read off the credentials rather than the account state, because the
     * credentials are what a charge actually needs: an account id present and no
     * usable keys is the definition of managed, and the two cannot disagree.
     */
    public function isManaged(): bool
    {
        return $this->credentials->isManaged();
    }

    /**
     * Which of the two ways this provider is provisioned, for the screen that
     * has to render the right panel: `platform_managed`, `own_keys`, or `none`
     * when nothing is connected at all.
     */
    public function paymentMode(): string
    {
        if ($this->isManaged()) {
            return 'platform_managed';
        }

        return $this->source === 'none' ? 'none' : 'own_keys';
    }
}
