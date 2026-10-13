<?php

use App\Services\Payments\Connect\BachsEventCatalogue;

/*
| WS-39 — every event Bachs documents, and what we do about each one.
|
| The point of this file is that the answer is *written down*. Before it, the
| connect endpoint asked the payment driver what to do, and the driver speaks
| only the three-word settlement vocabulary — so everything that was not a
| payment was answered, correctly, as "unhandled". An event we have decided not
| to act on and an event nobody has thought about are then indistinguishable,
| which is the failure this file exists to prevent.
|
| Bachs documents **27** event slugs. Twenty-three of them are subscribable from
| the dashboard (the `event_types` enum in its own OpenAPI document, which is
| also what `POST /v1/webhook-endpoints` validates against). The other four —
| `account.updated`, `capability.updated`, `transfer.created` and
| `payment_method.saved` — have pages of their own but are not in that enum, and
| `account.updated` and `capability.updated` are the ones the whole Connect flow
| depends on. All four are listed, because "not offered by the dashboard's
| checkbox" is not the same as "cannot arrive".
|
| The catalogue holds **28**: the 27 above, plus `payout.reversed`. That one is
| deliberately the exception to the rule at the top of this paragraph — it is in
| neither the enum nor, as far as we have been able to confirm, a page of its
| own. It is listed anyway, and the reason is the same one that put the other
| four here: a withdrawal that is recalled after it landed is a status this
| platform already knows how to handle (`PayoutSweepService` carries a
| `reversed` vocabulary and unwinds the wallet and both sets of books for it),
| and the only thing that could stop it being handled is the *name* the delivery
| arrives under. Left out, the delivery is logged as an unknown type and acted on
| by nobody, and the row it concerns is already `paid` — so the sweep will not
| re-read it either. The cost of being wrong is one inert table row; the cost of
| being right and absent is a business's money recorded as gone.
*/

test('every documented event has a disposition and a written reason', function () {
    $catalogue = BachsEventCatalogue::all();

    expect($catalogue)->toHaveCount(28);

    $dispositions = [
        BachsEventCatalogue::SETTLE,
        BachsEventCatalogue::ACCOUNT,
        BachsEventCatalogue::PAYOUT,
        BachsEventCatalogue::REFUND,
        BachsEventCatalogue::DISPUTE,
        BachsEventCatalogue::NOTE,
        BachsEventCatalogue::UNUSED,
    ];

    foreach ($catalogue as $row) {
        expect($dispositions)->toContain($row['disposition'])
            // Not "is set" but "says something": a reason of "unused" would
            // pass a null check and tell a reader nothing.
            ->and(mb_strlen($row['reason']))->toBeGreaterThan(20)
            ->and(BachsEventCatalogue::knows($row['type']))->toBeTrue();
    }
});

test('the four slugs that are documented but not subscribable are all accounted for', function () {
    // These cannot be ticked in the dashboard, which is exactly why a list built
    // from that checkbox would have missed the two the Connect flow turns on.
    expect(BachsEventCatalogue::knows('account.updated'))->toBeTrue()
        ->and(BachsEventCatalogue::knows('capability.updated'))->toBeTrue()
        ->and(BachsEventCatalogue::knows('transfer.created'))->toBeTrue()
        ->and(BachsEventCatalogue::knows('payment_method.saved'))->toBeTrue();

    expect(BachsEventCatalogue::dispositionFor('account.updated'))->toBe(BachsEventCatalogue::ACCOUNT)
        ->and(BachsEventCatalogue::dispositionFor('capability.updated'))->toBe(BachsEventCatalogue::ACCOUNT);
});

test('the events that move money are the ones wired to behaviour', function () {
    // The load-bearing half of the table. If one of these ever drifts to `NOTE`
    // or `UNUSED` the platform stops acting on money it is responsible for, and
    // nothing else in the suite would notice.
    expect(BachsEventCatalogue::dispositionFor('checkout.completed'))->toBe(BachsEventCatalogue::SETTLE)
        ->and(BachsEventCatalogue::dispositionFor('collection.succeeded'))->toBe(BachsEventCatalogue::SETTLE)
        ->and(BachsEventCatalogue::dispositionFor('payout.paid'))->toBe(BachsEventCatalogue::PAYOUT)
        ->and(BachsEventCatalogue::dispositionFor('payout.failed'))->toBe(BachsEventCatalogue::PAYOUT)
        // The one that moves money back the other way, and the only one that
        // arrives after the provider has already said the money landed.
        ->and(BachsEventCatalogue::dispositionFor('payout.reversed'))->toBe(BachsEventCatalogue::PAYOUT)
        ->and(BachsEventCatalogue::dispositionFor('refund.failed'))->toBe(BachsEventCatalogue::REFUND)
        ->and(BachsEventCatalogue::dispositionFor('dispute.created'))->toBe(BachsEventCatalogue::DISPUTE);
});

test('an event Bachs has never documented is unknown rather than quietly unused', function () {
    // The distinction the whole catalogue rests on: `UNUSED` means "we looked at
    // this and it cannot concern us", null means "nobody has looked". The
    // endpoint logs the second as a warning, which is how a newly published slug
    // announces itself instead of being absorbed for a year.
    expect(BachsEventCatalogue::dispositionFor('something.never.documented'))->toBeNull()
        ->and(BachsEventCatalogue::dispositionFor(''))->toBeNull()
        ->and(BachsEventCatalogue::knows('something.never.documented'))->toBeFalse();

    // Case and padding are the provider's business, not ours.
    expect(BachsEventCatalogue::dispositionFor('  PAYOUT.PAID '))->toBe(BachsEventCatalogue::PAYOUT);
});

test('the events we deliberately do not act on are about features we do not use', function () {
    // Every `UNUSED` names a Bachs product this platform never calls —
    // subscriptions, invoicing, saved payment methods, balance conversions.
    // Asserted as a count so that adding a slug to this bucket is a decision
    // somebody makes in the catalogue rather than a silent new gap.
    $counts = BachsEventCatalogue::counts();

    expect($counts[BachsEventCatalogue::UNUSED])->toBe(12)
        ->and($counts[BachsEventCatalogue::SETTLE])->toBe(2)
        ->and($counts[BachsEventCatalogue::PAYOUT])->toBe(4)
        ->and($counts[BachsEventCatalogue::REFUND])->toBe(3)
        ->and($counts[BachsEventCatalogue::DISPUTE])->toBe(2)
        ->and($counts[BachsEventCatalogue::ACCOUNT])->toBe(2)
        ->and(array_sum($counts))->toBe(28);
});
