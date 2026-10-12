<?php

namespace App\Services\Payments\OnBehalf;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Connect\ConnectRequirementResult;
use App\Services\Payments\Connect\ConnectResult;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\Gateways\PaystackApi;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\OnBehalf\Contracts\OnBehalfProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Support\Facades\Log;

/**
 * Paystack, as the account the platform charges a business's money through —
 * a **subaccount**, and the split Paystack makes when the customer pays.
 *
 * ## What is deliberately absent
 *
 * There is no balance read, no payout, no destination endpoint and no
 * requirement submission in this class, and the absence is a fact rather than an
 * oversight. A Paystack subaccount is created *with* its settlement bank, so it
 * is payable from the moment it exists; Paystack then pays it on its own
 * settlement schedule and reports that in settlement reports rather than in a
 * webhook we have to handle. There is nothing for us to pull and nothing for a
 * business to complete afterwards — which is why
 * {@see self::ensureDirectSettlement()} is a no-op here and the payout sweep has
 * no business anywhere near these accounts.
 *
 * The consequence worth stating plainly: **Paystack cannot be made to settle
 * instantly.** Money goes straight to the business rather than through an
 * account of ours, which is the platform's doctrine, but it goes on Paystack's
 * schedule (the next business day for Nigerian transactions). Bachs' `instant`
 * interval is the provider that can do the other thing.
 *
 * ## The split, and where it is decided
 *
 * `percentage_charge` is set once, at creation, as the **business's** share —
 * Paystack's own wording is "the percentage of the transaction that the
 * subaccount receives" — and it is derived from the platform's commission so
 * that a charge which somehow arrives with no per-transaction override still
 * splits in roughly the right direction.
 *
 * It is only a default, though, because the platform's commission is a
 * percentage *plus a flat sum*, and a flat sum cannot be expressed as a
 * percentage of an unknown amount. The authoritative split is sent per
 * transaction: {@see PaystackGateway} passes
 * `transaction_charge` — the platform's cut in kobo — which overrides
 * `percentage_charge` for that charge. `PlatformCommission::on()` is what
 * computes it, the same method the Bachs path uses for `platform_fee`, so the
 * two providers take the same money by construction rather than by agreement.
 */
final class PaystackOnBehalf implements OnBehalfProcessor
{
    /** The currency a subaccount settles in. A Paystack subaccount is NGN-only. */
    private const CURRENCY = 'NGN';

    /**
     * The floor Paystack accepts for `percentage_charge`. Zero would be a
     * subaccount that receives nothing, which Paystack rejects outright rather
     * than creating.
     */
    private const MIN_SHARE_PERCENT = 0.01;

    public function __construct(
        private readonly PlatformBillingGateway $platform,
    ) {}

    public function code(): string
    {
        return 'paystack';
    }

    /**
     * Create the subaccount, with the bank it settles into.
     *
     * `$entityType` is not sent: it is Bachs' concept (it decides which
     * verification requirements follow) and Paystack's subaccounts have no
     * equivalent. Accepted in the signature anyway so the caller does not have to
     * know which provider it holds.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     */
    public function provision(Business $business, ?Store $store, array $bank, string $entityType): ConnectResult
    {
        if (($problem = $this->platform->credentialProblem('paystack')) !== null) {
            return ConnectResult::failed('Storify cannot process payments at the moment: '.$problem);
        }

        $credentials = $this->platform->credentialsFor('paystack');

        $response = PaystackApi::client($credentials)->post(PaystackApi::baseUrl().'/subaccount', array_filter([
            // Paystack shows this name to the business on its own dashboard and
            // settlement reports, so it is the business's name rather than ours.
            'business_name' => (string) $business->name,
            'settlement_bank' => (string) ($bank['bank_code'] ?? ''),
            'account_number' => (string) ($bank['account_number'] ?? ''),
            'percentage_charge' => $this->businessSharePercent(),
        ], fn ($value) => $value !== null && $value !== ''));

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            // Paystack's refusals here are almost always about the bank details,
            // and its message ("Could not resolve account name") is written for
            // the person who typed them — so it is passed through rather than
            // replaced with a sentence of ours that says less.
            Log::warning('paystack.on_behalf.provision_refused', [
                'business_id' => $business->id,
                'status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return ConnectResult::failed(
                $body['message'] ?? 'Paystack could not create a settlement account for this business.',
                $body,
            );
        }

        $code = $data['subaccount_code'] ?? null;

        if (! is_string($code) || $code === '') {
            // Created but unnamed is unusable: every later call and every charge
            // addresses the subaccount by its code, and there would be nothing to
            // put on the connection row.
            Log::error('paystack.on_behalf.account_without_code', [
                'business_id' => $business->id,
                'keys' => array_keys($data),
            ]);

            return ConnectResult::failed('Paystack created a settlement account but did not return its code. It has been logged.', $body);
        }

        return ConnectResult::ok($code, $this->accountFrom($data, $code), $this->destinationFrom($data, $code, $bank), $body);
    }

    /**
     * Point an existing subaccount at a different bank.
     *
     * A different endpoint from Bachs' — Paystack updates the subaccount itself
     * (`PUT /subaccount/:code`) rather than registering a separate destination
     * resource, because a subaccount *is* its settlement destination.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     */
    public function registerDestination(string $accountId, array $bank): ConnectResult
    {
        if (($problem = $this->platform->credentialProblem('paystack')) !== null) {
            return ConnectResult::failed('Storify cannot process payments at the moment: '.$problem, [], $accountId);
        }

        $credentials = $this->platform->credentialsFor('paystack');

        $response = PaystackApi::client($credentials)->put(
            PaystackApi::baseUrl().'/subaccount/'.rawurlencode($accountId),
            array_filter([
                'settlement_bank' => (string) ($bank['bank_code'] ?? ''),
                'account_number' => (string) ($bank['account_number'] ?? ''),
            ], fn ($value) => $value !== null && $value !== ''),
        );

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            // The account id is returned with the failure, as it is on the Bachs
            // path: the subaccount exists and the next attempt has to land on it
            // rather than creating a second one for the same business.
            return ConnectResult::failed(
                $body['message'] ?? 'Paystack could not update this settlement account\'s bank details.',
                $body,
                $accountId,
            );
        }

        return ConnectResult::ok($accountId, $this->accountFrom($data, $accountId), $this->destinationFrom($data, $accountId, $bank), $body);
    }

    /**
     * Read the subaccount back.
     *
     * Null when it could not be read, never an empty account: overwriting the
     * last known-good state with `unknown` would take a business that can
     * currently be paid and mark it unusable for as long as Paystack is having a
     * bad day.
     */
    public function refresh(string $accountId): ?ManagedAccount
    {
        $credentials = $this->platform->credentialsFor('paystack');

        try {
            $response = PaystackApi::client($credentials)->get(
                PaystackApi::baseUrl().'/subaccount/'.rawurlencode($accountId)
            );
        } catch (\Throwable $e) {
            Log::warning('paystack.on_behalf.refresh_failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (! $response->successful() || ! ($body['status'] ?? false) || $data === []) {
            Log::warning('paystack.on_behalf.refresh_refused', [
                'account_id' => $accountId,
                'status' => $response->status(),
            ]);

            return null;
        }

        // The bank is not returned in full, so the stored masked destination is
        // preserved by the caller (`withDestination`) rather than reconstructed
        // from a payload that does not carry it.
        return $this->accountFrom($data, $accountId);
    }

    /**
     * Nothing to submit, ever.
     *
     * Paystack asks a subaccount for a name, a bank and an account number, all
     * of which arrive in {@see self::provision()}. There is no verification
     * questionnaire, no identity document list and no `requirements` block, so
     * the honest answer is that this pass had nothing to do.
     */
    public function submitRequirements(
        string $accountId,
        Business $business,
        ?Store $store = null,
        ?ManagedAccount $account = null,
    ): ConnectRequirementResult {
        return ConnectRequirementResult::nothingToDo($account ?? $this->refresh($accountId));
    }

    /**
     * Nothing to arrange: Paystack settles a subaccount automatically.
     *
     * `$instant` is accepted and ignored rather than refused, and the caller
     * gets a null (success) rather than a complaint, because "the business wants
     * its money immediately" is not a request that failed — it is one this
     * provider answers as soon as it can, which is its next settlement run.
     * Nothing here can pull that run earlier; see the class docblock.
     */
    public function ensureDirectSettlement(string $accountId, bool $instant): ?string
    {
        return null;
    }

    public function settlementMode(): string
    {
        return OnBehalfProcessor::SETTLEMENT_DIRECT;
    }

    /**
     * A subaccount can be charged through when it is active, and can pay out
     * because it was created with a settlement bank — the two things this asks.
     */
    public function isUsable(ManagedAccount $account): bool
    {
        return $account->canCharge($this->code()) && $account->canBePaidOut();
    }

    /**
     * The share Paystack keeps for the subaccount, from the platform's
     * commission — the business's percentage.
     *
     * Floored at Paystack's minimum: a commission of 100% is already refused by
     * validation, but a fixed-only commission with a percentage of zero would
     * ask for a subaccount that receives nothing, which Paystack rejects rather
     * than creating. The flat component is still taken per transaction, so a
     * business is never short-changed by this floor — it only stops the
     * subaccount's *default* from being zero.
     */
    private function businessSharePercent(): string
    {
        // Built rather than injected: `PlatformCommission`'s constructor is
        // private, because a commission that was not read from the settings row
        // is a commission someone guessed at. Read here, on use, for the same
        // reason the billing seam reads its own column on every call.
        $share = 100.0 - (PlatformCommission::fromSettings()->percentBasisPoints() / 100);

        if ($share < self::MIN_SHARE_PERCENT) {
            $share = self::MIN_SHARE_PERCENT;
        }

        // Two decimal places, as Paystack documents the field.
        return number_format($share, 2, '.', '');
    }

    /**
     * Paystack's subaccount payload, as the state the rest of the app reads.
     *
     * `active` is the whole of it: Paystack reports a subaccount as active or
     * not, with no capability vocabulary to translate — which is why the
     * registry declares none for this provider and
     * {@see ManagedAccount::canCharge()} reads an empty list as "no such check"
     * rather than "collects nothing".
     *
     * @param  array<string, mixed>  $data
     */
    private function accountFrom(array $data, string $accountId): ManagedAccount
    {
        $active = $data['active'] ?? true;

        return new ManagedAccount(status: $active ? ManagedAccount::STATUS_ACTIVE : ManagedAccount::STATUS_INACTIVE);
    }

    /**
     * The masked destination, in the same shape Bachs' accounts store.
     *
     * The number was sent to Paystack and is not kept — only the last four
     * digits and the bank's name, which is what the business needs to recognise
     * the account it is being paid into. `id` is the subaccount code, because
     * that is this provider's name for where the money goes.
     *
     * @param  array<string, mixed>  $data
     * @param  array{account_number?: string, account_name?: string, bank_code?: string, bank_name?: string|null}  $bank
     * @return array<string, mixed>
     */
    private function destinationFrom(array $data, string $accountId, array $bank): array
    {
        $active = (bool) ($data['active'] ?? true);

        return ManagedAccount::maskDestination([
            'id' => $accountId,
            // Payable is the account being active: settlement details were
            // supplied at creation and Paystack resolves them then, so there is
            // no separate "usable" flag to wait on as there is at Bachs.
            'is_usable' => $active,
            'status' => $active ? 'active' : 'inactive',
            'currency' => self::CURRENCY,
            'bank_name' => $bank['bank_name'] ?? ($data['settlement_bank'] ?? null),
            'account_name' => $data['account_name'] ?? ($bank['account_name'] ?? null),
        ], [
            ...$bank,
            'currency' => self::CURRENCY,
        ]);
    }
}
