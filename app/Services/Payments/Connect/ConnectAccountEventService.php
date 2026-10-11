<?php

namespace App\Services\Payments\Connect;

use App\Services\ActivityRecorder;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\Log;

/**
 * The connected-account events that are not a payment and not a payout.
 *
 * Three kinds land here, and they have one thing in common: none of them is
 * about an order, so none of them can be settled, and all three are still
 * things somebody has to be able to see afterwards.
 *
 * - **The account's own state** (`account.updated`, `capability.updated`). A
 *   capability the provider has restricted stops the storefront offering that
 *   payment method, and waiting for the next hourly sweep to notice would mean
 *   offering customers a method that fails at the provider's checkout. The
 *   handler re-reads the account and stores what it finds; a refresh is
 *   self-correcting, so an out-of-order or redelivered one is harmless by
 *   construction.
 * - **A chargeback** (`dispute.*`). Money is already gone — the provider debits
 *   a flat dispute fee the moment one is recorded — and there is a deadline to
 *   answer. What this does is record it faithfully and raise it in the activity
 *   log, which is what lets somebody notice. Submitting evidence is a human
 *   decision about a real customer and is deliberately **not** automated.
 * - **A refund's outcome** (`refund.*`). Our refund call returns as soon as the
 *   provider accepts it, which is not the same as the customer being paid: the
 *   amount is only *reserved* at that point. `refund.failed` is the delivery
 *   that says the money did not arrive, and without it a failed refund looks
 *   exactly like a successful one.
 *
 * Everything is keyed by the provider's own object id and kept as the latest
 * state of that object, so a redelivery or an out-of-order update overwrites
 * rather than appending a second copy.
 */
final class ConnectAccountEventService
{
    /** How many of each a connection row keeps. */
    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly ConnectConnections $connections,
        private readonly BachsConnectAccountService $accounts,
    ) {}

    /**
     * Re-read the account and store what the provider now says about it.
     *
     * The refresh is the whole point: the delivery itself only says *something*
     * changed, and the account's own state is the answer to what. A payload
     * cannot be trusted to be the newest one anyway — deliveries are not
     * ordered — so nothing is read off it.
     */
    public function refreshAccountState(string $accountId): bool
    {
        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            Log::info('bachs.account.unknown_account', ['account_id' => $accountId]);

            return false;
        }

        $account = $this->accounts->refresh($accountId);

        if ($account === null) {
            // Unreadable is not "nothing changed": leaving the stored state
            // alone is what keeps a business that can currently be charged from
            // being taken offline by the provider having a bad minute.
            Log::warning('bachs.account.state_unreadable', ['account_id' => $accountId]);

            return false;
        }

        $config = $this->connections->config($connection);
        $previous = is_array($config['managed'] ?? null) ? $config['managed'] : [];

        $config['managed'] = $account
            ->withDestination($this->destinationFrom($config))
            ->toConfig();
        $config['account_state_at'] = now()->toIso8601String();

        $this->connections->writeConfig($connection, $config);

        $this->noteStateChange($connection, $previous, $config['managed']);

        return true;
    }

    /**
     * Record a chargeback, and say so in the trail.
     *
     * The provider's payload is stored almost whole — a dispute is a real
     * financial event with a deadline attached, and re-deriving it later from a
     * narrower copy would lose the `reason` and the response window that decide
     * what happens next.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordDispute(string $accountId, array $data, string $type): bool
    {
        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            Log::warning('bachs.dispute.unknown_account', ['account_id' => $accountId]);

            return false;
        }

        $disputeId = $this->stringValue($data, 'dispute_id', 'id');

        if ($disputeId === null) {
            Log::warning('bachs.dispute.without_id', ['account_id' => $accountId, 'type' => $type]);

            return false;
        }

        $entry = array_filter([
            'id' => $disputeId,
            'charge_id' => $this->stringValue($data, 'charge_id'),
            'amount' => $this->stringValue($data, 'amount'),
            'amount_kobo' => ($amount = $this->stringValue($data, 'amount')) === null
                ? null
                : Naira::koboFromStrict($amount),
            'currency' => $this->stringValue($data, 'currency'),
            'status' => $this->stringValue($data, 'status'),
            'reason' => $this->stringValue($data, 'reason'),
            'is_response_editable' => $data['is_response_editable'] ?? null,
            'response_deadline_at' => $this->stringValue($data, 'response_deadline_at'),
            'opened_at' => $this->stringValue($data, 'created_at'),
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        $config = $this->connections->config($connection);
        $config['disputes'] = $this->upsert($config['disputes'] ?? [], $disputeId, $entry, 'opened_at');
        $this->connections->writeConfig($connection, $config);

        $scope = $this->connections->scopeOf($connection);
        $disputeStatus = (string) ($entry['status'] ?? 'unknown');

        ActivityRecorder::record(
            action: 'payment_gateway.dispute_'.$disputeStatus,
            description: 'Bachs dispute '.$disputeStatus.' on account '.$accountId,
            // Never `$data` wholesale: a dispute payload carries the customer's
            // card and billing detail, and the audit log is readable.
            new: [
                'dispute_id' => $disputeId,
                'charge_id' => $entry['charge_id'] ?? null,
                'amount' => $entry['amount'] ?? null,
                'currency' => $entry['currency'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'response_deadline_at' => $entry['response_deadline_at'] ?? null,
            ],
            metadata: ['provider' => 'bachs', 'event' => $type, 'account_id' => $accountId],
            businessId: $scope['business_id'],
        );

        Log::warning('bachs.dispute.recorded', [
            'account_id' => $accountId,
            'dispute_id' => $disputeId,
            'status' => $disputeStatus,
            'type' => $type,
        ]);

        return true;
    }

    /**
     * Record a refund's outcome.
     *
     * Raised in the activity trail only when it *failed*: a successful refund is
     * the expected end of a refund somebody already made, and logging every one
     * of them would bury the failures. A failure is money that did not reach a
     * customer, and it needs a person.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordRefund(string $accountId, array $data, string $type): bool
    {
        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            Log::info('bachs.refund.unknown_account', ['account_id' => $accountId]);

            return false;
        }

        $refundId = $this->stringValue($data, 'refund_id', 'id');

        if ($refundId === null) {
            Log::warning('bachs.refund.without_id', ['account_id' => $accountId, 'type' => $type]);

            return false;
        }

        $entry = array_filter([
            'id' => $refundId,
            'charge_id' => $this->stringValue($data, 'charge_id'),
            'reference' => $this->stringValue($data, 'reference'),
            'status' => $this->stringValue($data, 'status'),
            'requested_amount' => $this->stringValue($data, 'requested_amount'),
            'refunded_amount' => $this->stringValue($data, 'refunded_amount'),
            'currency' => $this->stringValue($data, 'currency'),
            'reason' => $this->stringValue($data, 'reason'),
            'failure_reason' => $this->stringValue($data, 'failure_reason'),
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        $config = $this->connections->config($connection);
        $config['refunds'] = $this->upsert($config['refunds'] ?? [], $refundId, $entry, 'updated_at');
        $this->connections->writeConfig($connection, $config);

        if (strtolower((string) ($entry['status'] ?? '')) === 'failed') {
            $scope = $this->connections->scopeOf($connection);

            ActivityRecorder::record(
                action: 'payment_gateway.refund_failed',
                description: 'Bachs could not complete a refund on account '.$accountId,
                new: [
                    'refund_id' => $refundId,
                    'charge_id' => $entry['charge_id'] ?? null,
                    'reference' => $entry['reference'] ?? null,
                    'requested_amount' => $entry['requested_amount'] ?? null,
                    'reason' => $entry['failure_reason'] ?? $entry['reason'] ?? null,
                ],
                metadata: ['provider' => 'bachs', 'event' => $type, 'account_id' => $accountId],
                businessId: $scope['business_id'],
            );

            Log::error('bachs.refund.failed', [
                'account_id' => $accountId,
                'refund_id' => $refundId,
                'reference' => $entry['reference'] ?? null,
            ]);
        }

        return true;
    }

    /**
     * The chargebacks recorded against a scope, most recently opened first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function disputes(int $businessId, ?int $storeId = null): array
    {
        return $this->recorded($businessId, $storeId, 'disputes');
    }

    /**
     * The refund outcomes recorded against a scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function refunds(int $businessId, ?int $storeId = null): array
    {
        return $this->recorded($businessId, $storeId, 'refunds');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recorded(int $businessId, ?int $storeId, string $key): array
    {
        $connection = $this->connections->for($businessId, $storeId);

        if ($connection === null) {
            return [];
        }

        $entries = $this->connections->config($connection)[$key] ?? null;

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * Replace the entry with this id, or add it — never a second copy.
     *
     * Keyed on the provider's id because that is what a redelivery repeats, and
     * capped so a connection that has taken thousands of refunds does not carry
     * all of them in a JSON column forever.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    private function upsert(array $entries, string $id, array $entry, string $openedKey): array
    {
        $kept = [];

        foreach ($entries as $existing) {
            if (! is_array($existing) || ($existing['id'] ?? null) === $id) {
                continue;
            }

            $kept[] = $existing;
        }

        array_unshift($kept, $entry);

        usort($kept, fn (array $a, array $b): int => strcmp(
            (string) ($b[$openedKey] ?? $b['updated_at'] ?? ''),
            (string) ($a[$openedKey] ?? $a['updated_at'] ?? ''),
        ));

        return array_slice($kept, 0, self::HISTORY_LIMIT);
    }

    /**
     * A one-line trail entry when a capability or the account's standing changed.
     *
     * Not recorded for every delivery: the provider re-sends `capability.updated`
     * whenever the reason behind a restriction changes, and an audit row per
     * redelivery would be noise that hides the one that mattered. Only an actual
     * difference between what we had and what we now read is written.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function noteStateChange(object $connection, array $previous, array $current): void
    {
        $before = is_array($previous['capabilities'] ?? null) ? $previous['capabilities'] : [];
        $after = is_array($current['capabilities'] ?? null) ? $current['capabilities'] : [];
        $standingChanged = ($previous['status'] ?? null) !== ($current['status'] ?? null);

        if (! $standingChanged && $before == $after) {
            return;
        }

        $scope = $this->connections->scopeOf($connection);

        ActivityRecorder::record(
            action: 'payment_gateway.account_state_changed',
            description: 'Bachs account '.$connection->gateway_account_id.' reported a new state',
            old: ['status' => $previous['status'] ?? null, 'capabilities' => $before],
            new: ['status' => $current['status'] ?? null, 'capabilities' => $after],
            metadata: ['provider' => 'bachs', 'account_id' => $connection->gateway_account_id],
            businessId: $scope['business_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function destinationFrom(array $config): ?array
    {
        $destination = $config['managed']['payout_destination'] ?? null;

        return is_array($destination) ? $destination : null;
    }

    /**
     * The first of several possible keys that holds a non-empty string.
     *
     * Alias names rather than one canonical key, because the provider's webhook
     * payloads and its REST objects do not always agree on a field's name and a
     * lookup that insisted on one of them would silently record nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
