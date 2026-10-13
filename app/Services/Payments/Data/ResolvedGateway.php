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
        /**
         * Whether this business's money is collected and paid out by Storify,
         * rather than settled to it by the provider.
         *
         * This is now the *only* way a platform-processed charge can be made.
         * The rail that used to charge on the platform's key while *carrying the
         * business's own account* — so the provider settled the business
         * directly — has been deleted along with the account machinery it needed,
         * and with it every per-business account id. What is left is one shape:
         * the charge is the platform's own, the gross lands in Storify's balance,
         * and a job pays each business from its wallet.
         *
         * It is still a flag rather than an assumption, because the wallet and
         * the ledger have to agree with the *column* when the money arrives
         * rather than with what the resolver believed at charge time — see
         * `Transaction::isPlatformManaged()` and the custody postings in
         * {@see LedgerPostingService}.
         */
        public readonly bool $custody = false,
    ) {}

    public function isUsable(): bool
    {
        return $this->isEnabled && ! $this->credentials->isEmpty();
    }

    /**
     * Whether the platform charges on this business's behalf here.
     *
     * True exactly when the business is one Storify collects for. There is no
     * longer a second way to arrive at that — the account the credentials used to
     * carry was deleted with the rest of the per-business rail — so this reads
     * the flag rather than inferring it from the credentials, which is the
     * opposite of how it was written when two managed shapes existed.
     */
    public function isManaged(): bool
    {
        return $this->custody;
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
