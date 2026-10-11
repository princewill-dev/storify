<?php

namespace App\Services\Payments\Connect;

use App\Services\Payments\Data\ManagedAccount;

/**
 * What one pass over an account's outstanding requirements did.
 *
 * Four lists rather than a boolean, because the honest answer is rarely "all
 * done" and the interesting part is always *which* of them it is:
 *
 * - `submitted` — fields we sent to the provider from data we already held.
 * - `waiting` — fields the provider is already checking, so re-sending them
 *   achieves nothing. Shown so a business is not asked for something twice.
 * - `unresolvable` — fields that are ours to supply in principle, with the
 *   sentence saying why we did not. Always actionable by a person, and the
 *   reason is the point: "we do not hold a registration number" and "the
 *   provider wants a document uploaded" are different jobs for different people.
 * - `with_provider` — `resolution: review`, i.e. paperwork already sitting with
 *   Bachs. Nothing for anyone on our side to do, and listed only so the screen
 *   does not look like it lost them.
 *
 * `account` is the state as it stood after the pass, re-read from the provider —
 * never an assumption that the submission worked, since the provider may accept
 * a value and still hold the requirement open while it verifies it.
 */
final class ConnectRequirementResult
{
    /**
     * @param  array<int, string>  $submitted
     * @param  array<string, string>  $unresolvable  field => why
     * @param  array<int, string>  $waiting
     * @param  array<int, string>  $withProvider
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $submitted = [],
        public readonly array $unresolvable = [],
        public readonly array $waiting = [],
        public readonly array $withProvider = [],
        public readonly ?ManagedAccount $account = null,
        public readonly ?string $message = null,
        public readonly bool $dryRun = false,
    ) {}

    /**
     * @param  array<int, string>  $submitted
     * @param  array<string, string>  $unresolvable
     * @param  array<int, string>  $waiting
     * @param  array<int, string>  $withProvider
     */
    public static function done(
        array $submitted,
        array $unresolvable,
        array $waiting,
        array $withProvider,
        ?ManagedAccount $account,
        bool $dryRun = false,
    ): self {
        return new self(true, $submitted, $unresolvable, $waiting, $withProvider, $account, null, $dryRun);
    }

    public static function failed(string $message): self
    {
        return new self(false, message: $message);
    }

    /** Nothing was outstanding in the first place. */
    public static function nothingToDo(?ManagedAccount $account): self
    {
        return new self(true, account: $account);
    }

    public function hasOutstanding(): bool
    {
        return $this->unresolvable !== [] || $this->waiting !== [];
    }

    /**
     * For the API response and the console.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'dry_run' => $this->dryRun,
            'message' => $this->message,
            'submitted' => $this->submitted,
            'unresolvable' => $this->unresolvable,
            'waiting' => $this->waiting,
            'with_provider' => $this->withProvider,
        ];
    }
}
