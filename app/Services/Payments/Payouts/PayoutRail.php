<?php

namespace App\Services\Payments\Payouts;

use App\Services\Payments\Data\GatewayCredentials;

/**
 * Moving money out of Storify's own balance to a business's bank.
 *
 * This is the seam the collect-and-pay-out model rests on, and it is two calls
 * rather than one because providers split them:
 *
 * 1. {@see ensureDestination()} teaches the provider where a business's money
 *    goes, from a bank code and an account number. It is deliberately separate
 *    from the transfer so it can be done once and reused, and so a business that
 *    has been registered but never paid still has somewhere to be paid *to*.
 * 2. {@see send()} moves a specific amount to it.
 *
 * **Both are asked for the platform's credentials, never a business's.** That is
 * the whole difference from the connected-account era: there is no per-business
 * account at the provider to address, so nothing has to exist there before a
 * business can sell — which is what makes switching a processor invisible. A
 * provider that did need something pre-created could not implement this honestly,
 * and the fix would be to say so rather than to provision quietly.
 *
 * Implementations must treat `$reference` as the idempotency key and pass it
 * through in whatever form the provider honours — a body field, a header, or
 * both. The caller generates it once per payout and reuses it across retries of
 * the same attempt, so a duplicate submission and a genuine second payout of the
 * same amount stay distinguishable.
 */
interface PayoutRail
{
    /** The registry code this rail serves — `bachs`, `paystack`. */
    public function code(): string;

    /**
     * The banks this provider can pay to, in its own code space.
     *
     * **This is what makes a processor switch possible at all.** A bank code is
     * only meaningful inside the list it came from, so translating one provider's
     * code into another's requires asking the other for its list — there is no
     * shared numbering to lean on, and the two disagree on exactly the banks a
     * Nigerian shop is most likely to use.
     *
     * Each entry is `['code' => string, 'name' => string, 'nibss' => string|null]`:
     * the code a payout takes, the name this provider uses, and the NIBSS number
     * where it exposes one separately. `nibss` matters because Bachs numbers 94 of
     * its 286 banks differently from NIBSS while keeping the NIBSS number in its own
     * field, and without it those entries would be unreachable from a code that
     * came from Paystack.
     *
     * **`nibss` is nullable and `code` and `name` are not.** A row missing either
     * is dropped by the caller rather than guessed at.
     *
     * Returns an empty array when the provider cannot be asked — never a partial
     * list, and never a throw. A caller that read "could not ask" as "has no banks"
     * would refuse every payout account on the platform for the length of a DNS
     * blip, which is the same failure mode {@see availableBalance()} guards against.
     *
     * @return array<int, array{code: string, name: string, nibss: string|null}>
     */
    public function banks(GatewayCredentials $credentials): array;

    /**
     * Register a bank account as a payout destination, or return the one that
     * already exists.
     *
     * **Treat this as creating something, not as finding it.** The obvious reading
     * — that a provider recognises an account it has already been given and hands
     * back the same destination — is documented by both providers and was measured
     * false on Bachs: three identical registrations returned three different ids.
     * So an implementation that is called repeatedly accumulates destinations at
     * the provider, and the guard against that belongs to the caller rather than
     * here.
     *
     * That guard is to call this **once, when a business names its payout account**,
     * and store the id against that account. Nothing on the payout path registers a
     * destination; the send is handed one that already exists.
     */
    public function ensureDestination(
        GatewayCredentials $credentials,
        string $accountNumber,
        string $bankCode,
        ?string $accountName,
        string $currency = 'NGN',
    ): PayoutDestinationRef;

    /**
     * Send the money.
     *
     * A success here means the provider *accepted* the transfer, not that it
     * landed — the difference is what the payout's own lifecycle and the
     * provider's webhook exist to close, and reporting acceptance as arrival
     * would mark a payout paid while it was still in flight.
     *
     * **The three outcomes are not two**, and the distinction is load-bearing:
     * `refused` is the only one that means the money definitely did not move, and
     * therefore the only one a caller may release a wallet reservation on. An
     * unreachable provider returns `indeterminate`, never a failure — see
     * {@see TransferOutcome}.
     */
    public function send(
        GatewayCredentials $credentials,
        string $destinationId,
        int $amountKobo,
        string $reference,
        string $currency = 'NGN',
    ): TransferOutcome;

    /**
     * What the platform can actually spend at this provider right now, in kobo.
     *
     * **This is the point at which the platform stops guessing.** The settlement
     * window in `config/custody.php` is an assumption about when a provider
     * releases a charge to us, and nothing verifies it; this asks. A sale is only
     * made payable when the money behind it is *observed* to be spendable, which
     * is what lets the window be cut to nothing without Storify advancing money it
     * does not have.
     *
     * **Null is not zero, and the difference is the whole contract.** Null means
     * the provider did not tell us — unreachable, refused, an answer we could not
     * parse. A caller that read that as "no money" would freeze every payout in the
     * platform for the length of a DNS blip, and the failure would look like a
     * provider balance of zero rather than like a network problem. Zero is a
     * measurement: the provider answered and said there is nothing spendable.
     * Implementations must therefore return null for anything they cannot read and
     * `0` only for an answer that genuinely says zero.
     *
     * Implementations that cannot offer this at all return null, which degrades
     * the caller to the settlement window alone rather than to no payouts.
     */
    public function availableBalance(GatewayCredentials $credentials): ?int;

    /**
     * Ask the provider what became of a transfer we are unsure about.
     *
     * The recovery path for an `indeterminate` send, and the thing that stops a
     * dropped webhook from stranding a payout row in `processing` forever.
     *
     * **Returns null when this rail cannot answer**, which is not the same as
     * answering "still pending". Providers differ: Paystack will look a transfer
     * up by our own reference, while Bachs only offers a lookup by the payout id
     * it issued — so for Bachs `$providerPayoutId` is required and a send that
     * never got an id back can only be resolved by its webhook, which carries the
     * reference we sent. A null therefore means *wait*, and a caller that treated
     * it as "not found, so release the reservation" would reintroduce exactly the
     * double payment {@see TransferOutcome::indeterminate()} exists to prevent.
     *
     * @param  string|null  $providerPayoutId  the provider's own id, where we have one
     */
    public function lookUp(
        GatewayCredentials $credentials,
        string $reference,
        ?string $providerPayoutId = null,
    ): ?TransferOutcome;
}
