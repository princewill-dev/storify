<?php

namespace App\Services\Payments\OnBehalf\Contracts;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Connect\ConnectRequirementResult;
use App\Services\Payments\Connect\ConnectResult;
use App\Services\Payments\Connect\PlatformPayoutService;
use App\Services\Payments\Data\ManagedAccount;

/**
 * One provider, as the platform's way of taking a business's money for it.
 *
 * Everything in this interface used to be Bachs-shaped and Bachs-named:
 * `BachsConnectAccountService`, `ConnectRequirementService` and a
 * `ConnectWebhookController::PROVIDER = 'bachs'` constant. That was correct while
 * Bachs was the only provider that could be provisioned for a business, and it
 * stopped being correct the moment the platform gained a *second* one — so the
 * vocabulary is here, and the provider-specific parts are behind it.
 *
 * **Names are deliberately provider-neutral.** A "connected account" is Bachs'
 * word; a "subaccount" is Paystack's; both are "the account the platform charges
 * through on a business's behalf", which is what this interface calls it. The
 * methods that only some providers have an answer for say so in their own
 * docblocks rather than being absent, because a caller that has to know which
 * provider it is holding is a caller that has not been abstracted from it.
 *
 * **`settlementMode()` is the load-bearing distinction.** `direct` means the
 * provider pays the business itself, out of the same settlement that collected
 * the money: there is no balance of ours and nothing for a sweep to pull.
 * `pull` means money lands in a balance we control and waits for
 * {@see PlatformPayoutService} to withdraw it.
 * The platform's doctrine is `direct` everywhere it can be, and this method is
 * what lets the payout service step off the money path rather than be deleted.
 */
interface OnBehalfProcessor
{
    /** How the business is actually paid. */
    public const SETTLEMENT_DIRECT = 'direct';

    /** Money lands with us first and a sweep has to withdraw it. */
    public const SETTLEMENT_PULL = 'pull';

    /** The registry code this processor answers for. */
    public function code(): string;

    /**
     * Create the account the platform will charge through, and register the bank
     * it should be paid into.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     */
    public function provision(Business $business, ?Store $store, array $bank, string $entityType): ConnectResult;

    /**
     * Register a bank against an account that already exists.
     *
     * Separate from {@see self::provision()} because an account cannot be
     * created twice: a business changing its bank has to land on the same
     * account rather than starting a second one.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     */
    public function registerDestination(string $accountId, array $bank): ConnectResult;

    /** The account's live state, or null when it could not be read. */
    public function refresh(string $accountId): ?ManagedAccount;

    /**
     * Submit whatever the provider is still asking for, from data we already
     * hold.
     *
     * Some providers ask nothing (Paystack's subaccounts have no verification
     * flow), and theirs returns `nothingToDo()` with the account unchanged.
     */
    public function submitRequirements(
        string $accountId,
        Business $business,
        ?Store $store = null,
        ?ManagedAccount $account = null,
    ): ConnectRequirementResult;

    /**
     * Make the provider pay the business automatically, so the money reaches the
     * business's bank with no step of ours in between.
     *
     * Returns the provider's refusal in words, or null when it is arranged. A
     * sentence rather than a boolean because a business whose settlement could
     * not be switched on is a business whose money is stranded, and the reason is
     * the only thing that says who has to fix it.
     */
    public function ensureDirectSettlement(string $accountId, bool $instant): ?string;

    /** One of the two `SETTLEMENT_*` constants. */
    public function settlementMode(): string;

    /**
     * Whether this account can both take money and hand it to the business.
     *
     * Both halves, never one: an account that can charge and cannot pay out is
     * the exact failure the platform's direct-settlement doctrine exists to
     * remove.
     */
    public function isUsable(ManagedAccount $account): bool;
}
