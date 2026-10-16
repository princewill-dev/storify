<?php

namespace App\Services\Payments\Payouts;

use App\Models\PayoutBankCode;
use App\Models\StoreBank;

/**
 * What one payout provider makes of one business bank account.
 *
 * The answer to a single question — "can this provider pay this business, and
 * with what" — and the reason it is its own type rather than a row in
 * `payout_bank_codes` is the order the work has to happen in. The setup step has
 * to know the answer **before** it writes the account, because a refusal has to
 * stop the write rather than arrive after it; and it has no bank row to attach a
 * row to until it does write.
 *
 * So resolution is a fact about a bank account and a provider — derivable from the
 * two of them alone, writable nowhere — and {@see PayoutBankResolver::persist()}
 * is what records it once there is a row to record it against.
 *
 * `reason` is set only when the answer is no, and it is written to be read by the
 * business: it names the bank and the provider, because "we cannot pay you" is not
 * something a shop can act on and "Paystack does not list Sparkle Bank" is.
 */
final class PayoutBankResolution
{
    public function __construct(
        public readonly string $provider,
        public readonly string $bankCode,
        public readonly ?string $destinationId = null,
        public readonly ?bool $usable = null,
        public readonly ?string $reason = null,
        /**
         * Whether this answer came from the provider rather than from a failure to
         * reach it.
         *
         * The flag exists for one decision, {@see self::blocksSetup()}, and it is
         * the difference between a business being told something true and being
         * turned away for our own outage.
         */
        public readonly bool $conclusive = true,

        /**
         * Whether we actually asked the provider to register this account.
         *
         * False only on the reporting pass of the backfill, which is a dry run and
         * must not create anything at the provider. A code we worked out without
         * asking is not a payable account, and it is not a refusal either — it is
         * the strongest answer available from reading a list, and {@see
         * self::blocksSetup()} treats it as neither.
         */
        public readonly bool $registered = true,
    ) {}

    /**
     * A provider that cannot pay this account, with the reason why.
     *
     * `bankCode` may still be present here, and that is deliberate: a code we
     * worked out and a registration that refused are two different failures, and
     * the code is worth keeping so the next attempt does not have to match the name
     * again.
     */
    public static function refused(string $provider, ?string $bankCode, string $reason): self
    {
        return new self(
            provider: $provider,
            bankCode: $bankCode ?? '',
            destinationId: null,
            usable: false,
            reason: $reason,
            conclusive: true,
            registered: true,
        );
    }

    /**
     * A code worked out from the provider's list, with the registration not asked
     * for.
     *
     * What a dry run produces. It answers "can this provider pay this bank at all"
     * — the question the backfill is reporting on — without creating an object at
     * the provider to find out, because a dry run that registers destinations is
     * not a dry run.
     */
    public static function unregistered(string $provider, string $bankCode): self
    {
        return new self(
            provider: $provider,
            bankCode: $bankCode,
            destinationId: null,
            usable: null,
            reason: null,
            conclusive: true,
            registered: false,
        );
    }

    /**
     * A provider we could not ask, so there is no answer yet.
     *
     * Deliberately not a refusal. A provider that is down, unreachable, or whose
     * bank list would not parse tells us nothing about whether it can pay this
     * business — and treating that as a no would turn every provider outage into a
     * platform that refuses to onboard anyone, which is a far wider failure than
     * the one it would be protecting against.
     *
     * Not payable either, so nothing is sent on the strength of it. The account is
     * simply recorded unanswered and re-resolved by {@see PayoutBankResolver}
     * when the backfill next runs.
     */
    public static function unchecked(string $provider, string $reason): self
    {
        return new self(
            provider: $provider,
            bankCode: '',
            destinationId: null,
            usable: null,
            reason: $reason,
            conclusive: false,
            registered: true,
        );
    }

    /**
     * Whether a payout can actually be sent through this provider.
     *
     * Both halves, and neither implies the other: a resolved code with no
     * destination is an account the provider would not accept, and there is no
     * case where a destination exists without a code.
     */
    public function payable(): bool
    {
        return $this->bankCode !== '' && filled($this->destinationId);
    }

    /**
     * Whether this answer should stop a business naming a payout account.
     *
     * Only a conclusive no does. The two ways of being unsure — we could not reach
     * the provider, or it would not accept the account just now — are recorded and
     * left to be retried, because neither is a reason to tell a shop it cannot be
     * paid. A provider that does not list the bank is the one verdict that is
     * certain and permanent, and it is exactly the bug this whole mechanism exists
     * to surface: Bachs does not carry the bank, so no amount of retrying pays it.
     *
     * Requires `registered`, so a code worked out by a dry run can never refuse
     * anything — it is an answer about a list, not about an account.
     */
    public function blocksSetup(): bool
    {
        return $this->registered && $this->conclusive && ! $this->payable();
    }

    /**
     * Whether this provider's numbering disagreed with the code we hold.
     *
     * The fact nobody could see before: a shared code is the quiet case and the
     * fintechs are the loud one, so this is what a log line reports to say the
     * translation did something.
     */
    public function translated(StoreBank $bank): bool
    {
        return $this->bankCode !== (string) $bank->bank_code;
    }

    /**
     * The row to write for this answer.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'bank_code' => $this->bankCode !== '' ? $this->bankCode : null,
            'destination_id' => $this->destinationId,
            'is_usable' => $this->usable,
            'failure_reason' => $this->reason,
            'resolved_at' => now(),
        ];
    }

    /**
     * Write this answer onto a stored account, replacing whatever was there.
     *
     * Upserted on `(store_bank_id, provider)` so a business that edits its account
     * number, or an operator re-running the backfill, replaces the answer instead
     * of leaving a second one beside it.
     */
    public function store(StoreBank $bank): PayoutBankCode
    {
        $row = PayoutBankCode::query()->firstOrNew([
            'store_bank_id' => $bank->id,
            'provider' => $this->provider,
        ]);

        $row->fill($this->attributes())->save();

        return $row;
    }
}
