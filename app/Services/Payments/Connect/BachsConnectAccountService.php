<?php

namespace App\Services\Payments\Connect;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\Gateways\BachsApi;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * The one place that talks to Bachs about a connected account.
 *
 * A business with no gateway of its own is given one of these instead: an
 * account under the platform's Bachs organisation, whose id we keep on the
 * payment-method row and send as `X-Account-Id` on every charge made for them.
 * The money is theirs from the moment the customer pays — the account is
 * merchant of record — so nothing here credits a balance of ours.
 *
 * **Three things are immutable and are therefore decided here, once.** The fee
 * collector (`bachs`, so the business's account settles net of the provider's
 * processing fee and Storify pays nothing per sale), the entity type
 * (individual or company — it decides which requirements the provider will ask
 * for), and the account itself, which can never be deleted. Getting any of them
 * wrong means a new account per business, which is why they come off the form
 * rather than being defaulted.
 *
 * **A provision that half-succeeds is kept, not rolled back.** If the account is
 * created and the payout destination is rejected, the account id is returned
 * along with the failure: the next attempt finishes *that* account. Creating a
 * second one would leave an undeletable orphan holding a business's identity,
 * and the provider's own records would show two of them.
 *
 * Every call goes through {@see BachsApi} with the platform's key and the
 * account id in the header — the header is what makes the request act as the
 * business rather than as us, and Bachs does not reject its absence.
 */
final class BachsConnectAccountService
{
    /**
     * Personas are the provider's vocabulary for "can take money" and "can be
     * paid out". Both are needed: a collection-only account has a balance it can
     * never withdraw, which is the state this whole feature exists to avoid.
     */
    private const MERCHANT_PERSONA = 'merchant';

    private const RECIPIENT_PERSONA = 'recipient';

    /** The `recipient` capability that authorises money leaving the account. */
    private const PAYOUT_CAPABILITY = 'payouts';

    /**
     * What the business *is*. Immutable at creation and it decides the
     * requirements that follow, so it is a form field and never inferred from
     * the shape of a name.
     */
    public const ENTITY_COMPANY = 'company';

    public const ENTITY_INDIVIDUAL = 'individual';

    /** The country a new account is scoped to, and the destination's currency. */
    private const COUNTRY = 'NG';

    private const CURRENCY = 'NGN';

    public function __construct(
        private readonly PlatformBillingGateway $platform,
    ) {}

    /**
     * Create the account and register the bank it should be paid into.
     *
     * `$store` is recorded only for the activity trail and the contact name: the
     * account belongs to the **business**, and a store that needs its own
     * payouts gets its own account by calling this again with that store — which
     * is how per-store individuality survives in a system where the money is no
     * longer pooled at all.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     */
    public function provision(Business $business, ?Store $store, array $bank, string $entityType): ConnectResult
    {
        if (($problem = $this->platform->credentialProblem('bachs')) !== null) {
            return ConnectResult::failed('Storify cannot process payments at the moment: '.$problem);
        }

        $entityType = $this->normalizeEntityType($entityType);
        $credentials = $this->platform->credentialsFor('bachs');

        $response = BachsApi::client($credentials)
            ->post(BachsApi::baseUrl($credentials).'/accounts', $this->accountPayload($business, $store, $entityType));

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return ConnectResult::failed($this->refusal($response, $body, 'Bachs could not create a payment account for this business.'), $body);
        }

        $accountId = $body['id'] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            // Created but unnamed is unusable: every later call addresses the
            // account by id, and there is nothing to store on the row.
            Log::error('bachs.connect.account_without_id', ['business_id' => $business->id, 'keys' => array_keys($body)]);

            return ConnectResult::failed('Bachs created an account but did not return its id. It has been logged.', $body);
        }

        return $this->registerDestination($accountId, $bank, $credentials, $body);
    }

    /**
     * Register a bank against an account that already exists, and read the
     * account back.
     *
     * The second half of {@see provision()}, and separately callable because an
     * account **cannot be deleted and cannot be created twice**: a business
     * changing its bank must land here, on the same account id, rather than
     * starting a second one. The provider's own records would otherwise show two
     * accounts for one business, both holding its identity documents.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     * @param  array<string, mixed>  $createdFrom  the creation response, for the log
     */
    public function registerDestination(
        string $accountId,
        array $bank,
        ?GatewayCredentials $credentials = null,
        array $createdFrom = [],
    ): ConnectResult {
        $credentials ??= $this->platform->credentialsFor('bachs');

        $destination = $this->submitPayoutDestination($accountId, $bank, $credentials);

        // Read back rather than trusting the creation response: `capabilities`
        // and `requirements` are only populated by a single-account read, and a
        // screen told the wrong thing about an account's state is a screen whose
        // owner waits for an approval that already happened — or tries to charge
        // through one that has not.
        $account = $this->refresh($accountId, $credentials);

        if ($account !== null && is_array($destination['detail'] ?? null)) {
            $account = $account->withDestination($destination['detail']);
        }

        if (! $destination['ok']) {
            return ConnectResult::failed(
                $destination['message'] ?? 'The payment account was created, but its bank account could not be registered.',
                $createdFrom,
                $accountId,
            );
        }

        if ($account === null) {
            Log::warning('bachs.connect.account_unreadable', ['account_id' => $accountId]);

            return ConnectResult::createdAt(
                $accountId,
                $destination['detail'],
                'Your payment account was created. Its status could not be read just now and will be checked again shortly.',
                $createdFrom,
            );
        }

        return ConnectResult::ok($accountId, $account, $destination['detail'], $createdFrom);
    }

    /**
     * Register the bank the account is paid into — `POST /v1/payouts/destinations`.
     *
     * A dedicated endpoint rather than a field on the account: it returns
     * `is_usable`, which is the only thing that says the destination will
     * actually accept money, and the first usable destination for a currency
     * becomes that currency's default without any further instruction. Nigerian
     * details are resolved at registration, so a wrong account number is refused
     * here rather than at the first payout.
     *
     * The account number is sent and forgotten — see
     * {@see ManagedAccount::maskDestination()}. What we keep is the id and the
     * last four digits.
     *
     * @param  array{account_number: string, account_name: string, bank_code: string, bank_name?: string|null}  $bank
     * @return array{ok: bool, detail: array<string, mixed>|null, message: string|null}
     */
    public function submitPayoutDestination(
        string $accountId,
        array $bank,
        ?GatewayCredentials $credentials = null,
    ): array {
        $credentials ??= $this->platform->credentialsFor('bachs');

        $submitted = array_filter([
            'currency' => self::CURRENCY,
            'type' => 'bank_account',
            'account_number' => $bank['account_number'] ?? null,
            'account_name' => $bank['account_name'] ?? null,
            'bank_code' => $bank['bank_code'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $response = BachsApi::client($credentials, $accountId)
            ->post(BachsApi::baseUrl($credentials).'/payouts/destinations', $submitted);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            Log::warning('bachs.connect.destination_refused', [
                'account_id' => $accountId,
                'status' => $response->status(),
                'code' => $body['code'] ?? null,
            ]);

            return [
                'ok' => false,
                'detail' => null,
                'message' => $this->refusal($response, $body, 'Bachs could not register this bank account for payouts.'),
            ];
        }

        return [
            'ok' => true,
            'detail' => ManagedAccount::maskDestination($body, $submitted),
            'message' => null,
        ];
    }

    /**
     * Read the account's live state — the only call that populates capabilities
     * and requirements.
     *
     * Returns null when the provider could not be reached or answered with
     * something unreadable. Null is deliberately *not* an empty account: an
     * unreadable account must not overwrite the last known-good state with
     * "unknown", because that would take a business that can currently be paid
     * offline for as long as the provider is having a bad day.
     */
    public function refresh(string $accountId, ?GatewayCredentials $credentials = null): ?ManagedAccount
    {
        $credentials ??= $this->platform->credentialsFor('bachs');

        try {
            $response = BachsApi::client($credentials, $accountId)->get(
                BachsApi::baseUrl($credentials).'/accounts/'.rawurlencode($accountId)
            );
        } catch (\Throwable $e) {
            Log::warning('bachs.connect.refresh_failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bachs.connect.refresh_refused', [
                'account_id' => $accountId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json() ?? [];

        if (! is_array($body) || $body === []) {
            return null;
        }

        return ManagedAccount::fromAccountPayload($body);
    }

    /**
     * The account payload.
     *
     * `responsibilities.fees.collector` is the one line the whole fee model turns
     * on. `bachs` — the default, and what is written here explicitly so a change
     * of the provider's default cannot move it — means the processing fee comes
     * out of the charge and the business's account settles **net**. The
     * alternative, `platform`, would debit our balance on every sale and settle
     * the business gross. It is fixed at creation and has no endpoint to change
     * it.
     *
     * @return array<string, mixed>
     */
    private function accountPayload(Business $business, ?Store $store, string $entityType): array
    {
        return [
            // Where the provider sends anything about this account. Falls back to
            // the owner's, because a business row with no email is common and the
            // provider requires one.
            'contact_email' => $this->contactEmail($business, $store),
            'display_name' => $store?->name ?? $business->name,
            // The provider defaults this to the platform's country. Sent anyway:
            // it decides which requirements apply, and a platform that ever
            // operates from elsewhere must not silently onboard every business
            // into the wrong regime.
            'country' => self::COUNTRY,
            'entity_type' => $entityType,
            'configuration' => [
                // Taking money. The capabilities come from the registry so the
                // list the provider is asked for and the list the resolver
                // requires a grant from are the same list — asking for one thing
                // and gating on another is how an account ends up approved and
                // still unable to charge.
                self::MERCHANT_PERSONA => [
                    'capabilities' => $this->requested(PaymentGatewayRegistry::managedCapabilitiesFor('bachs')),
                ],
                // Moving money out. Without this the account can collect and can
                // never withdraw, which is not a business account at all.
                self::RECIPIENT_PERSONA => [
                    'capabilities' => $this->requested([self::PAYOUT_CAPABILITY]),
                ],
            ],
            'responsibilities' => [
                'fees' => ['collector' => 'bachs'],
            ],
        ];
    }

    /**
     * Capability names as the provider's request objects.
     *
     * @param  array<int, string>  $capabilities
     * @return array<string, array{requested: bool}>
     */
    private function requested(array $capabilities): array
    {
        $requested = [];

        foreach ($capabilities as $capability) {
            $requested[(string) $capability] = ['requested' => true];
        }

        return $requested;
    }

    private function normalizeEntityType(string $entityType): string
    {
        return $entityType === self::ENTITY_INDIVIDUAL ? self::ENTITY_INDIVIDUAL : self::ENTITY_COMPANY;
    }

    /**
     * The provider's own refusal, when it named one.
     *
     * Its `code` is the part worth trusting — `invalid_configuration`,
     * `capability_configuration_mismatch` — and its message is written for a
     * developer. Both are logged; the sentence a business owner reads is ours.
     *
     * @param  array<string, mixed>  $body
     */
    private function refusal(Response $response, array $body, string $fallback): string
    {
        $code = $body['code'] ?? null;
        $message = $body['message'] ?? null;

        Log::warning('bachs.connect.refused', [
            'status' => $response->status(),
            'code' => $code,
        ]);

        // A validation failure names the offending fields, and the owner is the
        // only one who can fix them. Named fields, never a value.
        if ($response->status() === 422 && is_array($body['errors'] ?? null)) {
            $fields = array_keys($body['errors']);

            return 'Some of those details were rejected: '.implode(', ', array_map('strval', $fields)).'.';
        }

        if ($response->status() === 403) {
            return 'Storify is not authorised to create payment accounts right now. Please contact support.';
        }

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * Where the provider writes to this account.
     *
     * The store's published address first — it is the one the business already
     * answers — then the owner's account email, then the platform's from-address
     * so that the provider always receives something non-empty.
     */
    private function contactEmail(Business $business, ?Store $store): string
    {
        $candidates = [$store?->support_email, $business->owner?->email];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return (string) config('mail.from.address', 'support@storify.test');
    }
}
