<?php

namespace App\Services\Payments\OnBehalf;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Connect\BachsConnectAccountService;
use App\Services\Payments\Connect\ConnectRequirementResult;
use App\Services\Payments\Connect\ConnectRequirementService;
use App\Services\Payments\Connect\ConnectResult;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\Gateways\BachsApi;
use App\Services\Payments\OnBehalf\Contracts\OnBehalfProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Support\Facades\Log;

/**
 * Bachs, as the account the platform charges a business's money through — and,
 * since this change, as the provider that pays the business **itself**.
 *
 * The provisioning half is not new: it is {@see BachsConnectAccountService} and
 * {@see ConnectRequirementService}, which were already Bachs-specific and stay
 * exactly as they were. All this class adds is the one thing the platform's
 * direct-settlement doctrine needs from Bachs and never asked for before —
 * {@see self::ensureDirectSettlement()}, which tells Bachs to pay each connected
 * account automatically rather than holding its balance until our sweep comes
 * for it.
 *
 * ## Why the sweep was the wrong shape
 *
 * The old model was customer → our connected account → our hourly
 * `POST /payouts` → the business's bank. Two hops of ours in the middle of
 * somebody else's money, one of them a cron that has to be alive. Bachs can do
 * it in one: an account with `interval: "instant"` is paid as each collection
 * settles, by Bachs, with no step of ours in between and no balance of ours to
 * hold. That is the whole switch.
 *
 * `interval: "manual"` is still sent for a business whose `auto_payout` is off,
 * and it is not a failure — it is the platform deliberately keeping the old
 * shape, where the money waits in the connected account and the sweep (or an
 * admin's "pay out now") withdraws it. So both modes stay reachable, and which
 * one a business is in is stated by the same switch that always stated it.
 */
final class BachsOnBehalf implements OnBehalfProcessor
{
    /** The currency whose payout schedule is being set. */
    private const CURRENCY = 'NGN';

    public function __construct(
        private readonly BachsConnectAccountService $accounts,
        private readonly ConnectRequirementService $requirements,
        private readonly PlatformBillingGateway $platform,
    ) {}

    public function code(): string
    {
        return 'bachs';
    }

    public function provision(Business $business, ?Store $store, array $bank, string $entityType): ConnectResult
    {
        return $this->accounts->provision($business, $store, $bank, $entityType);
    }

    public function registerDestination(string $accountId, array $bank): ConnectResult
    {
        return $this->accounts->registerDestination($accountId, $bank);
    }

    public function refresh(string $accountId): ?ManagedAccount
    {
        return $this->accounts->refresh($accountId);
    }

    public function submitRequirements(
        string $accountId,
        Business $business,
        ?Store $store = null,
        ?ManagedAccount $account = null,
    ): ConnectRequirementResult {
        return $this->requirements->submit($accountId, $business, $store, account: $account);
    }

    /**
     * Tell Bachs to pay this account automatically, or to hold its balance.
     *
     * `POST /v1/balance_settings`, addressed to the account by `X-Account-Id` —
     * the endpoint takes no account id in its body, and without the header this
     * would set the *platform's* schedule and leave the business's money sitting
     * where it was.
     *
     * The default destination needs no instruction: Bachs makes the first usable
     * destination for a currency that currency's default, which is what
     * {@see BachsConnectAccountService::submitPayoutDestination()} creates. So
     * the only thing that can be missing here is the schedule.
     *
     * **Three consecutive payout failures revert the account to `manual`**, and
     * Bachs reports that with a `disabled_reason`. This method does not retry
     * blindly: a schedule that reverted because the bank refuses money is a
     * conversation with the business, not a loop. What it does do is report the
     * refusal in the provider's own words, which is what the caller shows.
     */
    public function ensureDirectSettlement(string $accountId, bool $instant): ?string
    {
        if (($problem = $this->platform->credentialProblem('bachs')) !== null) {
            return 'Storify cannot settle payments at the moment: '.$problem;
        }

        $credentials = $this->platform->credentialsFor('bachs');

        try {
            $response = BachsApi::client($credentials, $accountId)->post(
                BachsApi::baseUrl($credentials).'/balance_settings',
                [
                    'schedule_by_currency' => [
                        self::CURRENCY => [
                            // `instant` pays out as each collection settles;
                            // `manual` is the old behaviour, where the money waits
                            // for our sweep. See the class docblock.
                            'interval' => $instant ? 'instant' : 'manual',
                            'payout_currency' => self::CURRENCY,
                        ],
                    ],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('bachs.on_behalf.settlement_failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return 'Bachs could not be reached to arrange your payouts. They will be tried again shortly.';
        }

        if (! $response->successful()) {
            $body = $response->json() ?? [];

            Log::warning('bachs.on_behalf.settlement_refused', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'code' => $body['code'] ?? null,
            ]);

            return $body['message'] ?? 'Bachs refused to change how this account is paid out.';
        }

        return null;
    }

    /**
     * Direct: Bachs pays the business, on the schedule just arranged.
     *
     * Reported as `direct` even when the schedule is `manual`, and that is not a
     * contradiction — the mode describes *who moves the money*, and under this
     * class it is Bachs, with our sweep reduced to an admin-triggered fallback
     * for accounts deliberately left on `manual`.
     */
    public function settlementMode(): string
    {
        return OnBehalfProcessor::SETTLEMENT_DIRECT;
    }

    /**
     * Chargeable *and* payable. Bachs needs both halves asked: a collection-only
     * account has a balance it can never withdraw, which is the exact state the
     * platform's direct-settlement doctrine exists to remove.
     */
    public function isUsable(ManagedAccount $account): bool
    {
        return $account->canCharge($this->code()) && $account->canBePaidOut();
    }
}
