<?php

namespace App\Services\Payments\Connect;

/**
 * Every event Bachs documents, and what this platform does with it.
 *
 * The endpoint used to decide what to do about a delivery by asking the payment
 * driver, which only speaks the three-word settlement vocabulary
 * (`payment_success`, `payment_failed`, `ignored`) and knows nothing about
 * accounts, payouts, refunds or disputes. Everything that was not a settlement
 * therefore fell into one bucket that was answered — correctly, but blind — as
 * "unhandled". This is the list that replaces that guess.
 *
 * **It is a written-down decision, not a filter.** An event is absent here only
 * if Bachs does not document it, and an absent type is logged as a surprise
 * rather than ignored quietly. Each entry says which of a handful of things we
 * do, and why, so that "we do nothing about `invoice.paid`" is a statement
 * somebody made rather than a gap nobody noticed — which matters, because five
 * of these events move money we are responsible for and eleven are about
 * features this platform does not use at all.
 *
 * ## The dispositions
 *
 * - {@see SETTLE} — this is a customer's payment; hand it to the settlement
 *   processor, which re-verifies with the provider and is idempotent.
 * - {@see ACCOUNT} — the account's own state changed, and there is nothing left
 *   to do about it. This used to re-read the account and store its capabilities,
 *   because a capability the provider had withdrawn had to stop the storefront
 *   offering it; the per-business account was deleted with the rail that needed
 *   it, so a delivery about one is now answered and read by nobody. Kept as its
 *   own disposition rather than folded into {@see UNUSED}, because these *are*
 *   about us — they are about a thing we deliberately stopped keeping.
 * - {@see PAYOUT} — an outcome for money leaving. Recorded against the payout
 *   row it is about.
 * - {@see REFUND} — an outcome for money going back to a customer.
 * - {@see DISPUTE} — a chargeback. Recorded and raised, and a lost one takes the
 *   business's share back out of its wallet; evidence submission is a human
 *   decision and deliberately not automated.
 * - {@see NOTE} — understood, logged, and nothing to do. Each of these is
 *   explained below rather than left to look like an oversight.
 * - {@see UNUSED} — about a Bachs feature this platform does not use, so no
 *   delivery of it can concern us.
 *
 * ## Where the list comes from
 *
 * The twenty-three subscribable slugs are the `event_types` enum in Bachs' own
 * OpenAPI document (`WebhookEndpoint.event_types`, which is also what
 * `POST /v1/webhook-endpoints` validates against). The last four are documented
 * on the events pages but are **not** in that enum — `account.updated` and
 * `capability.updated` are the account-state events the Connect flow depends
 * on, `transfer.created` is reported for every transfer, and
 * `payment_method.saved` appears in the overview table with no page of its own.
 * They are listed here because "not offered by our dashboard's checkbox" is not
 * the same as "cannot arrive", and an unknown slug is worth a log line either
 * way.
 */
final class BachsEventCatalogue
{
    public const SETTLE = 'settle';

    public const ACCOUNT = 'account';

    public const PAYOUT = 'payout';

    public const REFUND = 'refund';

    public const DISPUTE = 'dispute';

    public const NOTE = 'note';

    public const UNUSED = 'unused';

    /**
     * Event slug => [disposition, why].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EVENTS = [
        // ---- A customer paid, or did not --------------------------------
        'checkout.completed' => [self::SETTLE, 'The order was paid, on Storify\'s own credentials, on the business\'s behalf.'],
        'collection.succeeded' => [self::SETTLE, 'The charge on a checkout cleared.'],

        // `collection.failed` deliberately does not cancel anything: the
        // provider keeps the checkout open so the customer can try another
        // method, and only `checkout.expired` ends it. Marking the order failed
        // here would cancel checkouts that still go on to be paid.
        'collection.failed' => [self::NOTE, 'One attempt was refused; the checkout stays open so another can be made.'],
        'checkout.expired' => [self::NOTE, 'The checkout timed out. Not always final, so nothing is cancelled on it.'],
        // Crypto-only: a bank transfer that arrives short simply never
        // completes. Where a shortfall does settle, the processor's own floor
        // (`chargeAmountMinor`) is what refuses it — a webhook is a
        // notification, not proof of the amount.
        'collection.underpaid' => [self::NOTE, 'Less than the asking price arrived; the settlement floor decides, not this.'],

        // ---- The account itself -----------------------------------------
        //
        // Nothing to re-read: the account these are about was the per-business
        // account at the provider, which no longer exists. Ours still arrives
        // here — the platform has an account at Bachs — so these are received,
        // verified, and answered 200 without being acted on.
        'account.updated' => [self::ACCOUNT, 'The account\'s details or standing changed; nothing depends on it any more.'],
        'capability.updated' => [self::ACCOUNT, 'A capability was granted, restricted or withdrawn; nothing depends on it any more.'],

        // ---- Money leaving ----------------------------------------------
        // Ours as well as a business's: under collect-and-pay-out every payout
        // is Storify's own balance paying a business out.
        'payout.created' => [self::PAYOUT, 'A withdrawal was accepted and queued.'],
        'payout.paid' => [self::PAYOUT, 'A withdrawal reached the bank.'],
        'payout.failed' => [self::PAYOUT, 'A withdrawal was refused; the wallet reservation goes back for a retry.'],
        // The one that arrives *after* `paid`, and the only event that undoes
        // something the provider has already told us: the bank sent the money
        // back, so what was recorded as paid out has to stop being recorded that
        // way. Unlisted, it would be logged as an unknown type and acted on by
        // nobody — and the row it is about is already `paid`, which means the
        // sweep will never look at it again either.
        'payout.reversed' => [self::PAYOUT, 'A withdrawal was returned by the bank; the wallet is credited back and the books unwound.'],

        // ---- Money going back -------------------------------------------
        // Our refund call is synchronous but the provider's answer is only
        // "accepted"; these are where the outcome actually lands.
        'refund.created' => [self::REFUND, 'A refund was accepted and its amount reserved.'],
        'refund.paid' => [self::REFUND, 'A refund reached the customer.'],
        'refund.failed' => [self::REFUND, 'A refund did not reach the customer and needs attention.'],

        // ---- A chargeback -----------------------------------------------
        'dispute.created' => [self::DISPUTE, 'A customer disputed a charge; a fee is already debited.'],
        'dispute.updated' => [self::DISPUTE, 'A dispute changed status or its response window moved.'],

        // ---- Bachs features this platform does not use ------------------
        //
        // None of these can carry anything about an order, a payout or a
        // dispute. Each names a product we never call, so acting on one would
        // mean inventing a meaning for data we do not collect.
        'conversion.completed' => [self::UNUSED, 'Currency conversions between balances; we convert at charge time instead.'],
        'conversion.failed' => [self::UNUSED, 'Currency conversions between balances; we convert at charge time instead.'],
        'transfer.created' => [self::UNUSED, 'Platform-to-account transfers; money here moves by direct charge and payout.'],
        'customer.created' => [self::UNUSED, 'Bachs customers are for saved payment methods, which checkout does not use.'],
        'customer.updated' => [self::UNUSED, 'Bachs customers are for saved payment methods, which checkout does not use.'],
        'payment_method.saved' => [self::UNUSED, 'Saved payment methods are a Bachs product this checkout does not offer.'],
        'customer.subscription.created' => [self::UNUSED, 'Bachs subscriptions are for customers of a business; platform billing is our own.'],
        'customer.subscription.updated' => [self::UNUSED, 'Bachs subscriptions are for customers of a business; platform billing is our own.'],
        'customer.subscription.deleted' => [self::UNUSED, 'Bachs subscriptions are for customers of a business; platform billing is our own.'],
        'invoice.created' => [self::UNUSED, 'Bachs invoicing is unused; nothing here raises one.'],
        'invoice.paid' => [self::UNUSED, 'Bachs invoicing is unused; nothing here raises one.'],
        'invoice.payment_failed' => [self::UNUSED, 'Bachs invoicing is unused; nothing here raises one.'],
    ];

    /**
     * What to do about an event, or null when Bachs never documented it.
     *
     * Null is the return that matters: the caller logs it as an unknown type
     * rather than treating it as one of the quiet `UNUSED` ones, so a slug the
     * provider adds later shows up in the log the first time it is delivered
     * instead of being absorbed silently for a year.
     */
    public static function dispositionFor(string $type): ?string
    {
        $entry = self::EVENTS[strtolower(trim($type))] ?? null;

        return $entry[0] ?? null;
    }

    /**
     * Why that event is handled the way it is, for a log line or the console.
     */
    public static function reasonFor(string $type): ?string
    {
        $entry = self::EVENTS[strtolower(trim($type))] ?? null;

        return $entry[1] ?? null;
    }

    public static function knows(string $type): bool
    {
        return isset(self::EVENTS[strtolower(trim($type))]);
    }

    /**
     * The whole catalogue, for the console to render and the test to walk.
     *
     * @return array<int, array{type: string, disposition: string, reason: string}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (self::EVENTS as $type => [$disposition, $reason]) {
            $rows[] = ['type' => $type, 'disposition' => $disposition, 'reason' => $reason];
        }

        return $rows;
    }

    /**
     * The slugs as `WebhookEvent`-style counts, for a quick summary.
     *
     * @return array<string, int> disposition => how many events it covers
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (self::EVENTS as [$disposition]) {
            $counts[$disposition] = ($counts[$disposition] ?? 0) + 1;
        }

        return $counts;
    }
}
