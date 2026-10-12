<?php

namespace App\Services\Payments\Data;

use App\Services\Payments\Connect\ConnectRequirementLabels;
use App\Support\Payments\PaymentGatewayRegistry;

/**
 * A connected account's state, as we last read it from the provider.
 *
 * Held in the connection row's `config` under `managed`, and read back by the
 * resolver, the payout service and the management screen. One typed
 * representation rather than a loose array in three places, because the same
 * question — *can this business be charged through and paid out?* — is asked in
 * all three and must get the same answer.
 *
 * The account **id** is deliberately not here. It lives in its own indexed
 * column so an incoming webhook can find its tenant without reading JSON; see
 * the migration that adds it.
 *
 * Everything in here is either non-secret or already masked: a payout
 * destination is recorded by its last four digits, and the full number is sent
 * to the provider and forgotten.
 *
 * **Two shapes, one representation.** The provider reports a capability as an
 * object (`{"card_collection": {"status": "active"}}`) but nothing outside this
 * class should have to know that, and the docs do not pin what a read returns
 * for every field — so both readings are normalized here rather than at each
 * call site, where a second reader would eventually disagree with the first.
 */
final class ManagedAccount
{
    /**
     * Capability states. Only `active` authorises anything — a capability that
     * is `pending`, `pending_review` or `restricted` may not be charged
     * through, and one that is `unrequested` was never asked for.
     */
    public const CAPABILITY_ACTIVE = 'active';

    /** The account status that means the provider will authenticate it. */
    public const STATUS_ACTIVE = 'active';

    /**
     * The provider has deactivated the account, so it can neither authenticate
     * nor move funds. Distinct from `unknown`, which means we have not read it.
     */
    public const STATUS_INACTIVE = 'inactive';

    /** Nothing has been read yet. Deliberately not treated as active. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Whether this account may take money right now. */
    public readonly bool $isActive;

    /**
     * @param  array<string, mixed>  $capabilities  capability name => status, or
     *                                              the provider's nested objects
     * @param  array<int, array<string, mixed>>  $requirements  what the provider still wants
     * @param  array<string, mixed>|null  $payoutDestination  masked, as submitted
     */
    public function __construct(
        public readonly string $status = self::STATUS_UNKNOWN,
        array $capabilities = [],
        array $requirements = [],
        public readonly ?array $payoutDestination = null,
    ) {
        $this->isActive = strtolower($status) === self::STATUS_ACTIVE;
        $this->capabilities = self::normalizeCapabilities($capabilities);
        $this->requirements = self::normalizeRequirements($requirements);
    }

    /**
     * Capability name => status, as a flat map.
     *
     * @var array<string, string>
     */
    public readonly array $capabilities;

    /**
     * @var array<int, array<string, mixed>>
     */
    public readonly array $requirements;

    /**
     * The state we stored last time, read back off the connection row.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromConfig(?array $stored): self
    {
        if ($stored === null) {
            return new self;
        }

        return new self(
            status: (string) ($stored['status'] ?? 'unknown'),
            capabilities: is_array($stored['capabilities'] ?? null) ? $stored['capabilities'] : [],
            requirements: is_array($stored['requirements'] ?? null) ? $stored['requirements'] : [],
            payoutDestination: is_array($stored['payout_destination'] ?? null)
                ? $stored['payout_destination']
                : null,
        );
    }

    /**
     * A live `GET /v1/accounts/{id}` response, in the same representation.
     *
     * The persona-keyed `configuration` block is deliberately not read: it says
     * what was *requested*, and what authorises a charge is the capability's
     * status under `capabilities`. Reading the request as though it were the
     * grant would offer a customer a payment method the provider has not
     * cleared.
     *
     * **The account has no `status` field.** The provider reports its standing
     * as a boolean, `is_active` — "when `false`, the account is deactivated and
     * cannot authenticate or move funds" — so the word this class stores is
     * derived from that boolean rather than read off the payload. Reading a
     * `status` key that is not there would read `unknown`, and `unknown` is not
     * active, so every managed store would have been refused a charge with a
     * screen insisting the account was still being reviewed. A `status` string
     * is still honoured when one is present, because a payload we cannot see
     * today should not be the reason a business cannot be paid.
     *
     * @param  array<string, mixed>  $account
     */
    public static function fromAccountPayload(array $account): self
    {
        return new self(
            status: self::statusFrom($account),
            capabilities: is_array($account['capabilities'] ?? null) ? $account['capabilities'] : [],
            requirements: is_array($account['requirements'] ?? null) ? $account['requirements'] : [],
        );
    }

    /**
     * The account's standing, from whichever of the two shapes it arrives in.
     *
     * `is_active` decides when it is present — including when it is `false`,
     * which is a definite answer and not a missing one. Absent both, the answer
     * is `unknown`: falsey, so nothing is offered on the strength of it.
     *
     * @param  array<string, mixed>  $account
     */
    private static function statusFrom(array $account): string
    {
        if (array_key_exists('is_active', $account)) {
            return $account['is_active'] ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
        }

        $status = $account['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : self::STATUS_UNKNOWN;
    }

    /**
     * A fresh read of the account, keeping the destination we already hold.
     *
     * The two are written by different endpoints — the account endpoint knows
     * nothing of payout destinations — so a refresh that dropped the stored one
     * would make a payable business look unpayable every time it was read.
     */
    public function withDestination(?array $destination): self
    {
        return new self(
            status: $this->status,
            capabilities: $this->capabilities,
            requirements: $this->requirements,
            payoutDestination: $destination ?? $this->payoutDestination,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'status' => $this->status,
            'capabilities' => $this->capabilities,
            'requirements' => $this->requirements,
            'payout_destination' => $this->payoutDestination,
        ];
    }

    /**
     * Capabilities the provider has cleared, out of the ones this integration
     * needs to take money at all.
     *
     * Intersected with the registry's list rather than reported wholesale: a
     * capability the provider enables by default (`crypto`, say) is not evidence
     * this integration can charge, and the screen should not show a business a
     * list of things that has nothing to do with the button beside it.
     *
     * @return array<int, string>
     */
    /**
     * No default provider, deliberately. The capability vocabulary is the
     * provider's — Bachs asks which collections a subaccount may perform, and
     * Paystack has no such concept — so a defaulted `$code` would be one
     * provider's answer silently applied to another's account. Callers always
     * know which provider they are looking at; making them say so is cheaper
     * than the class having to guess.
     *
     * @return array<int, string>
     */
    public function grantedCapabilities(string $code): array
    {
        $wanted = PaymentGatewayRegistry::managedCapabilitiesFor($code);

        return array_values(array_filter(
            $wanted,
            fn (string $capability): bool => ($this->capabilities[$capability] ?? null) === self::CAPABILITY_ACTIVE,
        ));
    }

    /**
     * Whether this account can take a payment right now.
     *
     * Both halves are load-bearing. A subaccount with no cleared capability
     * cannot collect through any method, and one the provider has deactivated
     * cannot authenticate at all — charging through either is a customer-facing
     * error we can foresee.
     *
     * **A provider that asks for no capabilities is judged on its status alone.**
     * Bachs requires each collection type to be requested and cleared; Paystack's
     * subaccounts have no equivalent, so an empty `capabilities` list in the
     * registry means "this provider has no such check", not "this provider may
     * collect nothing". Treating the two the same is how adding a second
     * provider silently makes every account on it unchargeable.
     */
    public function canCharge(string $code): bool
    {
        if (! $this->isActive) {
            return false;
        }

        return PaymentGatewayRegistry::managedCapabilitiesFor($code) === []
            || $this->grantedCapabilities($code) !== [];
    }

    /**
     * Whether money can leave: a destination has been submitted and the provider
     * has not rejected it.
     */
    public function canBePaidOut(): bool
    {
        $destination = $this->payoutDestination;

        return (bool) ($destination['is_usable'] ?? false) && $this->payoutDestinationId() !== null;
    }

    public function payoutDestinationId(): ?string
    {
        $id = $this->payoutDestination['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * What the provider still wants, in the shape the screen renders.
     *
     * `resolution: review` is separated from the rest because it needs no action
     * from anyone on our side — the paperwork is already with the provider — and
     * showing it as an outstanding task would send a business owner looking for
     * something to do.
     *
     * @return array{actionable: array<int, array<string, mixed>>, with_provider: array<int, array<string, mixed>>}
     */
    public function outstandingRequirements(): array
    {
        $actionable = [];
        $withProvider = [];

        foreach ($this->requirements as $requirement) {
            if (($requirement['resolution'] ?? null) === 'review') {
                $withProvider[] = $requirement;

                continue;
            }

            $actionable[] = $requirement;
        }

        return ['actionable' => $actionable, 'with_provider' => $withProvider];
    }

    public function hasOutstandingRequirements(): bool
    {
        return $this->outstandingRequirements()['actionable'] !== [];
    }

    /**
     * The same two groups, in words a business owner can act on.
     *
     * The provider asks in field paths and answers in field paths, and the one
     * place a human reads them is the payment settings screen — where
     * `persons.per_3a91c0d7.id_document` is not an instruction. The label says
     * what is wanted, and `reason` says why it has not been filled in for them
     * already, which is the difference between a list they can clear and one
     * they will ignore.
     *
     * @return array{actionable: array<int, array<string, mixed>>, with_provider: array<int, array<string, mixed>>}
     */
    public function requirementPayload(): array
    {
        $groups = $this->outstandingRequirements();

        $describe = function (array $entry): array {
            $field = (string) ($entry['field'] ?? '');

            return [
                'field' => $field === '' ? null : $field,
                'label' => ConnectRequirementLabels::label($field),
                'reason' => ConnectRequirementLabels::note($field),
                'status' => $entry['status'] ?? null,
                'deadline' => $entry['deadline'] ?? null,
                // Which capabilities this is holding up, so the screen can say
                // whether it is stopping payments or only payouts.
                'restricts_capabilities' => array_values((array) ($entry['restricts_capabilities'] ?? [])),
                // The provider's own rejection reasons, which are written for
                // the account holder and are safe to show.
                'errors' => array_values(array_filter(array_map(
                    fn ($error): ?string => is_array($error) ? ($error['reason'] ?? $error['code'] ?? null) : null,
                    (array) ($entry['errors'] ?? []),
                ))),
            ];
        };

        return [
            'actionable' => array_map($describe, $groups['actionable']),
            'with_provider' => array_map($describe, $groups['with_provider']),
        ];
    }

    /**
     * Masked destination for display: enough to recognise the account, never
     * enough to use it. The full number was never stored.
     *
     * @return array<string, mixed>|null
     */
    public function maskedDestination(): ?array
    {
        $destination = $this->payoutDestination;

        if ($destination === null) {
            return null;
        }

        return [
            'bank_name' => $destination['bank_name'] ?? null,
            'account_name' => $destination['account_name'] ?? null,
            'last4' => $destination['last4'] ?? null,
            'currency' => $destination['currency'] ?? null,
            'is_usable' => (bool) ($destination['is_usable'] ?? false),
        ];
    }

    /**
     * A destination as we keep it: no account number, ever.
     *
     * Built from the provider's registration response, which returns the id and
     * whether it is usable. The number the owner typed goes to the provider and
     * is not written anywhere on our side — a bank account number is the
     * business's to hold, not ours, and one more copy is one more thing to leak.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $submitted  what was sent, for the four digits and the label
     * @return array<string, mixed>
     */
    public static function maskDestination(array $response, array $submitted): array
    {
        $number = (string) ($submitted['account_number'] ?? '');

        return [
            'id' => isset($response['id']) ? (string) $response['id'] : null,
            'is_usable' => (bool) ($response['is_usable'] ?? false),
            'status' => isset($response['status']) ? (string) $response['status'] : null,
            'currency' => strtoupper((string) ($response['currency'] ?? $submitted['currency'] ?? 'NGN')),
            'bank_name' => $response['bank_name'] ?? $submitted['bank_name'] ?? null,
            'account_name' => strtoupper((string) ($response['account_name'] ?? $submitted['account_name'] ?? '')),
            'last4' => $number === '' ? null : substr($number, -4),
        ];
    }

    /**
     * The provider reports capabilities as nested objects; the rest of this
     * class wants one status string each.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private static function normalizeCapabilities(array $raw): array
    {
        $capabilities = [];

        foreach ($raw as $name => $value) {
            // `{"card_collection": "active"}` — what we store.
            if (is_string($value)) {
                $capabilities[(string) $name] = $value;

                continue;
            }

            // `{"card_collection": {"status": "active", ...}}` — what the
            // provider returns. Anything else is not a status and is skipped
            // rather than guessed at: reading a missing status as granted would
            // offer a payment method the provider has not cleared.
            if (is_array($value) && is_string($value['status'] ?? null)) {
                $capabilities[(string) $name] = $value['status'];
            }
        }

        return $capabilities;
    }

    /**
     * The requirements block, as one list of entries however it arrived.
     *
     * A single-account read returns an object of four buckets
     * (`currently_due`, `eventually_due`, `past_due`, `pending_verification`)
     * plus `entries` — a per-field view of the same requirements, each carrying
     * the capabilities it blocks, `errors` and a `resolution`. **`entries` is
     * what this class wants**, because a bare field key cannot say whether it is
     * ours to fill in or already sitting with the provider; the buckets are
     * therefore only a fallback for a payload that has no entries, and are
     * flattened into entries of the same shape so no caller has to care which it
     * got.
     *
     * @param  array<mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeRequirements(array $raw): array
    {
        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        foreach (['entries', 'outstanding'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                return array_values(array_filter($raw[$key], 'is_array'));
            }
        }

        return self::entriesFromBuckets($raw);
    }

    /**
     * The four buckets, flattened into entries.
     *
     * `resolution`, `restricts_capabilities` and `errors` are absent here
     * because a bucket is a bare list of field keys and carries none of them —
     * so an entry made this way has a field and a status and nothing else. That
     * is enough for the screen to name what is missing, and deliberately not
     * enough for {@see outstandingRequirements()} to claim a field is ours to
     * submit: without a `resolution` of `api` it is reported as needing
     * attention rather than auto-filled from data that may not be the right
     * answer to the question being asked.
     *
     * @param  array<mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private static function entriesFromBuckets(array $raw): array
    {
        $entries = [];

        foreach (['currently_due', 'eventually_due', 'past_due', 'pending_verification'] as $status) {
            foreach ((array) ($raw[$status] ?? []) as $field) {
                if (is_string($field) && $field !== '') {
                    $entries[] = ['field' => $field, 'status' => $status];
                }
            }
        }

        return $entries;
    }
}
