<?php

namespace App\Services\Payments\Connect;

use App\Services\Payments\Data\ManagedAccount;

/**
 * The outcome of one operation against a connected account.
 *
 * A message rather than a bare boolean because every caller has to say
 * something — the owner at the payout-account form, the admin on the business
 * console, a job writing a log line — and the provider's own refusal is usually
 * the most useful sentence available. Returning success alone would make each of
 * them invent an explanation for the same failure.
 *
 * A failure carries whatever was read before it went wrong. A provisioning that
 * created the account and then failed at the payout destination is *not* a
 * rollback: the account exists, it cannot be deleted, its fee collector can
 * never change, and the caller has to be given its id so the next attempt
 * completes the same account rather than creating a second one.
 */
final class ConnectResult
{
    /**
     * @param  array<string, mixed>  $raw  the provider's own response, for the log
     * @param  array<string, mixed>|null  $destination  masked, never the account number
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $accountId = null,
        public readonly ?ManagedAccount $account = null,
        public readonly ?array $destination = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function ok(
        string $accountId,
        ManagedAccount $account,
        ?array $destination = null,
        array $raw = [],
    ): self {
        return new self(true, $accountId, $account, $destination, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $message, array $raw = [], ?string $accountId = null): self
    {
        return new self(false, $accountId, null, null, $message, $raw);
    }

    /**
     * The account exists but its state could not be read.
     *
     * Not a failure — the provider was unreachable or answered with something
     * unreadable, and the next refresh will settle it. Reported with a sentence
     * so the owner is not left wondering whether the submission worked.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function createdAt(string $accountId, ?array $destination, string $message, array $raw = []): self
    {
        return new self(true, $accountId, null, $destination, $message, $raw);
    }
}
