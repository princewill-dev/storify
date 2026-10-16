<?php

use App\Enums\TransactionStatus;
use App\Mail\PayoutBlockedMail;
use App\Mail\PayoutFailedMail;
use App\Mail\PayoutPaidMail;
use App\Mail\PayoutReversedMail;
use App\Mail\WalletCreditedMail;
use App\Models\Business;
use App\Models\BusinessNotification;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyNotifier;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery as M;

/*
| WS-48 — telling a business about its money.
|
| Before this there was no alert for either event in collect-and-pay-out: no payout
| mailable existed at all, and the one payment mail path that did exist never fired
| on the automated webhook settlement route, which is the route nearly every sale
| takes. A business learned it had been paid by looking at its bank statement.
|
| Three properties are what these tests are for, and each of them is a way the
| feature can be wrong while appearing to work:
|
| **Once.** Every event here is *already* claimed exactly once upstream — the
| `settle_at` claim on a wallet credit, and the status claim in `settle()`, `fail()`
| and `undo()`. So the alerts are tested against a replay at both layers: the
| upstream claim (a webhook arriving twice, a `reconcile()` read-back of a row a
| webhook already settled) and the notification's own `dedupe_key`, because the
| second is what holds if the first ever widens.
|
| **Two channels that cannot take each other down.** The `business_notifications`
| row is the in-app record and the mail is the push. A mail that cannot be sent
| leaves the record standing and does not roll back the money — the failure mode to
| design out is a mail fault swallowing the notification entirely.
|
| **A crossing, not a state.** The "we cannot pay you" alert is raised from the
| failure that crosses the ceiling rather than from the send that is now skipping
| the business: the send asks on every attempt, and an alert raised from the asking
| would re-fire on each one forever.
*/

function pn48Settings(array $attributes): void
{
    $settings = Setting::query()->first();

    if ($settings === null) {
        $settings = Setting::query()->create(['company_name' => 'Storify']);
    }

    $settings->update($attributes);
}

function pn48Platform(): void
{
    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/** @return array{0: Business, 1: Store} */
function pn48Business(array $ownerAttributes = []): array
{
    [$owner, $business] = createBusinessOwner($ownerAttributes, ['auto_payout' => true]);

    $bank = StoreBank::create([
        'business_id' => $business->id,
        'bank_name' => 'Guaranty Trust Bank',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'is_primary' => true,
    ]);

    $business->forceFill([
        'custody_enabled' => true,
        'custody_enabled_at' => now(),
        'payout_bank_id' => $bank->id,
    ])->save();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'pn48-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ]);

    return [$business->fresh(), $store];
}

/** A collected sale whose share is exactly `$kobo`. Zero fees, so net == amount. */
function pn48Sale(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $order = $store !== null ? Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'order_number' => 'ORD-'.strtoupper(Str::random(10)),
        'subtotal' => $kobo / 100,
        'total' => $kobo / 100,
    ]) : null;

    return Transaction::create([
        'reference' => 'TXN-'.strtoupper(Str::random(12)),
        'business_id' => $business->id,
        'order_id' => $order?->id,
        'amount' => $kobo / 100,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'fee_kobo' => 0,
        'platform_fee_kobo' => 0,
        'metadata' => ['payment_mode' => 'platform_managed', 'leg_method' => 'bachs'],
    ]);
}

function pn48Funded(Business $business, int $kobo, ?Store $store = null): Transaction
{
    $sale = pn48Sale($business, $kobo, $store);
    $custody = app(CustodyService::class);

    $custody->recordCollectedSale($sale, 'bachs');
    $custody->promoteSettled();

    return $sale->fresh();
}

function pn48ProviderAccepts(): void
{
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
    ]);
}

function pn48Payouts(?Business $business = null): Collection
{
    return Payout::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->orderBy('id')
        ->get();
}

function pn48Notices(?Business $business = null, ?string $type = null): Collection
{
    return BusinessNotification::query()
        ->when($business !== null, fn ($query) => $query->where('business_id', $business->id))
        ->when($type !== null, fn ($query) => $query->where('type', $type))
        ->orderBy('id')
        ->get();
}

test('a collected sale tells the business once, by record and by mail', function () {
    pn48Platform();
    Mail::fake();

    [$business, $store] = pn48Business();

    $sale = pn48Funded($business, 100000, $store);

    $notice = pn48Notices($business, BusinessNotification::TYPE_WALLET_CREDITED)->sole();

    expect($notice->level)->toBe(BusinessNotification::LEVEL_SUCCESS)
        ->and($notice->title)->toContain('NGN 1,000.00')
        ->and($notice->amount_kobo)->toBe(100000)
        ->and($notice->currency)->toBe('NGN')
        // The subject is the sale itself, so the record can be followed back to the
        // transaction it is about rather than only to the business.
        ->and($notice->subject_type)->toBe(Transaction::class)
        ->and($notice->subject_id)->toBe((int) $sale->id)
        ->and($notice->user_id)->toBe((int) $business->owner->id)
        ->and($notice->emailed_at)->not->toBeNull()
        ->and($notice->mail_error)->toBeNull()
        // The figure the mail has to explain. Written at the moment of the credit, so
        // the money is in the *settling* half of the wallet and none of it is payable
        // yet — the distinction the whole model turns on, and the one a single
        // "balance" figure would have flattened into a lie.
        ->and($notice->metadata['pending_kobo'])->toBe(100000)
        ->and($notice->metadata['available_kobo'])->toBe(0)
        ->and($notice->metadata['balance_kobo'])->toBe(100000)
        ->and(str_contains($notice->body, $store->name))->toBeTrue()
        ->and(Str::contains($notice->body, 'NGN 1,000.00'))->toBeTrue();

    // Rendered, not merely delivered. `Mail::fake()` records a mailable without
    // building it, so a notifier that passed the wrong figures — the net where the
    // gross belongs — or a template reading a property the mailable never passes
    // would satisfy every other assertion in this file and fail in production.
    Mail::assertSent(WalletCreditedMail::class, function (WalletCreditedMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'NGN 1,000.00')
            && str_contains($html, 'Payment details')
            // Zero fee on this fixture, so the row is absent rather than
            // printing "Processing fee NGN 0.00".
            && ! str_contains($html, 'Processing fee')
            // And no word of the split, the wallet or the clearing behind it.
            && ! str_contains($html, 'share')
            && ! str_contains($html, 'wallet');
    });

    $mail = Mail::sent(WalletCreditedMail::class)->sole();

    // The gross — what the customer paid — and not the net, which on this fixture
    // happens to be the same number, so the assertion is about the argument the
    // notifier chose rather than about the arithmetic.
    expect($mail->amount)->toBe('NGN 1,000.00')
        ->and($mail->fee)->toBeNull();
});

test('a sale delivered twice — a replayed webhook — is announced once', function () {
    pn48Platform();
    Mail::fake();

    [$business, $store] = pn48Business();

    $sale = pn48Sale($business, 100000, $store);
    $custody = app(CustodyService::class);

    expect($custody->recordCollectedSale($sale, 'bachs'))->toBeTrue();

    // The same delivery again, which is ordinary rather than exceptional.
    expect($custody->recordCollectedSale($sale->fresh(), 'bachs'))->toBeFalse();

    expect(pn48Notices($business, BusinessNotification::TYPE_WALLET_CREDITED))->toHaveCount(1)
        ->and($business->fresh()->pending_balance)->toBe(100000);

    Mail::assertSent(WalletCreditedMail::class, 1);
});

test('a payout that lands is announced once, and both guards refuse the second call', function () {
    pn48Platform();
    pn48Settings(['system_payment_processor' => 'bachs']);
    Mail::fake();

    [$business, $store] = pn48Business();
    pn48Funded($business, 120000, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response(['id' => 'pay_1', 'status' => 'pending']),
        'sandbox-api.bachs.io/v1/payouts/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'completed']),
    ]);

    $sweep = app(PayoutSweepService::class);
    $sweep->sweep();

    $payout = pn48Payouts($business)->firstOrFail();

    expect(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_PAID))->toHaveCount(0);

    Payout::query()->update(['requested_at' => now()->subHour()]);

    expect($sweep->reconcile()['settled'])->toBe(1);

    $notice = pn48Notices($business, BusinessNotification::TYPE_PAYOUT_PAID)->sole();

    // The wallet was debited when the payout was *reserved*, not when it landed, so
    // "what is left" is the balance as it stands — saying otherwise would make the
    // mail contradict the dashboard the business is looking at.
    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID)
        ->and($notice->title)->toContain('NGN 1,200.00')
        ->and($notice->metadata['reference'])->toBe($payout->reference)
        ->and($notice->metadata['wallet_remaining_kobo'])->toBe(0)
        ->and($notice->emailed_at)->not->toBeNull();

    Mail::assertSent(PayoutPaidMail::class, 1);

    // The provider tells us again. The status claim in `settle()` is the first lock
    // and it holds: the row is already `paid`, so the update matches nothing and the
    // hook is not reached at all.
    expect($sweep->applyPayoutWebhook([
        'reference' => $payout->reference,
        'status' => 'completed',
    ], 'payout.completed'))->toBeTrue();

    // And the second lock holds on its own, which is the point of having one: the
    // notification is called directly, the way a caller that skipped the claim
    // would, and the unique key refuses it.
    expect(app(CustodyNotifier::class)->payoutPaid($payout->fresh()))->toBeNull();

    expect(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_PAID))->toHaveCount(1);

    Mail::assertSent(PayoutPaidMail::class, 1);
});

test('a refused payout is announced with the provider\'s own words, and the money is back', function () {
    pn48Platform();
    pn48Settings(['system_payment_processor' => 'bachs']);
    Mail::fake();

    [$business, $store] = pn48Business();
    pn48Funded($business, 90000, $store);

    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response(['id' => 'pd_new', 'is_usable' => true]),
        'sandbox-api.bachs.io/v1/payouts' => Http::response([
            'code' => 'ACCOUNT_NAME_MISMATCH',
            'message' => 'The account name does not match the account number.',
        ], 400),
    ]);

    app(PayoutSweepService::class)->sweep();

    $payout = pn48Payouts($business)->firstOrFail();
    $notice = pn48Notices($business, BusinessNotification::TYPE_PAYOUT_FAILED)->sole();

    // The provider's own sentence is what makes this actionable: a business that
    // reads "the account name does not match" fixes it in an afternoon, and one
    // that reads "payout failed" asks somebody.
    expect($notice->level)->toBe(BusinessNotification::LEVEL_WARNING)
        ->and($notice->title)->toContain('NGN 900.00')
        ->and($notice->body)->toContain('The account name does not match the account number.')
        ->and($notice->metadata['failure_code'])->toBe('ACCOUNT_NAME_MISMATCH')
        // Zero, and not a miscount: the ceiling only counts a *destination* the
        // provider would not accept, because a refusal with a code of the
        // provider's own is a fact about the transfer rather than about the account.
        // See `PayoutSweepService::DESTINATION_FAILURES`.
        ->and($notice->metadata['attempt'])->toBe(0)
        ->and($notice->metadata['wallet_balance_kobo'])->toBe(90000)
        ->and($payout->fresh()->failure_reason)->toContain('does not match')
        // Nothing was lost: the reservation was released and the wallet credited
        // before this mail was written, so the mail's claim and the wallet agree.
        ->and($business->fresh()->available_balance)->toBe(90000)
        // The reassurance, in the words a business reads rather than the words
        // the wallet is kept in: "back in your wallet" is our vocabulary.
        ->and($notice->body)->toContain('Nothing was lost')
        ->and($notice->body)->not->toContain('wallet')
        ->and($notice->emailed_at)->not->toBeNull();

    Mail::assertSent(PayoutFailedMail::class, 1);
});

test('a refusal a business does nothing about is announced again, and the ceiling is a crossing', function () {
    pn48Platform();
    pn48Settings(['system_payment_processor' => 'bachs']);
    config(['custody.max_destination_failures' => 2]);
    Mail::fake();

    [$business, $store] = pn48Business();
    pn48Funded($business, 50000, $store);

    // A destination the provider will not accept, which is the failure the ceiling
    // is made of — and deliberately not a refusal with a code of the provider's own:
    // those are facts about a transfer, not about the account, and counting them
    // would let a bad week of provider errors stop a perfectly good bank.
    Http::fake([
        'sandbox-api.bachs.io/v1/payouts/destinations' => Http::response([
            'id' => 'pd_bad',
            'is_usable' => false,
        ]),
    ]);

    $sweep = app(PayoutSweepService::class);

    $sweep->sweep();

    expect(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_BLOCKED))->toHaveCount(0);

    // Second refusal: the same money is owed, so the same amount is attempted
    // again, and this is the failure that crosses the ceiling.
    $sweep->sweep();

    expect(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_FAILED))->toHaveCount(2)
        ->and(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_BLOCKED))->toHaveCount(1);

    $blocked = pn48Notices($business, BusinessNotification::TYPE_PAYOUT_BLOCKED)->sole();

    expect($blocked->level)->toBe(BusinessNotification::LEVEL_CRITICAL)
        ->and($blocked->metadata['attempts'])->toBe(2)
        ->and($blocked->metadata['account_number'])->toBe('0123456789')
        // The argument the message makes: money is being held that the business
        // cannot receive until it acts.
        ->and($blocked->amount_kobo)->toBe(50000)
        ->and($blocked->body)->toContain('NGN 500.00')
        // The two failures are told as two because the key carries the attempt:
        // a business that changed nothing and was refused again has been told
        // something new, and a key on the payout alone would have hidden it.
        ->and(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_FAILED)->pluck('dedupe_key')->unique())
        ->toHaveCount(2);

    // And the alert does not re-fire on every pass that follows, which is the
    // whole reason it is raised from the crossing rather than from the sweep.
    $counts = $sweep->sweep();

    expect($counts['exhausted'])->toBe(1)
        ->and($counts['failed'])->toBe(0)
        ->and(pn48Notices($business, BusinessNotification::TYPE_PAYOUT_BLOCKED))->toHaveCount(1);

    Mail::assertSent(PayoutFailedMail::class, 2);
});

test('a mail that cannot be sent leaves the record standing and the money where it is', function () {
    pn48Platform();

    // A mail transport refusing mid-sweep. It is not hypothetical on a deployment
    // with no guaranteed queue worker, and the failure mode to design out is the
    // one where a mail fault swallows the notification entirely.
    $mailer = M::mock(Mailer::class);
    $mailer->shouldReceive('to')->andThrow(new RuntimeException('SMTP refused the connection'));
    Mail::swap($mailer);

    [$business, $store] = pn48Business();

    pn48Funded($business, 45000, $store);

    $notice = pn48Notices($business, BusinessNotification::TYPE_WALLET_CREDITED)->sole();

    expect($notice->emailed_at)->toBeNull()
        ->and($notice->mail_error)->toContain('SMTP refused the connection')
        // The two channels are independent, and both halves of that matter: the
        // record survives, and the money is credited exactly as it would have been.
        ->and($business->fresh()->available_balance)->toBe(45000)
        ->and($business->fresh()->pending_balance)->toBe(0);
});

test('a business with no mail address still gets the record', function () {
    pn48Platform();
    Mail::fake();

    [$business, $store] = pn48Business(['email' => '']);

    pn48Funded($business, 30000, $store);

    $notice = pn48Notices($business, BusinessNotification::TYPE_WALLET_CREDITED)->sole();

    // `emailed_at` null with no `mail_error` is the true and distinguishable
    // statement that there was no one to mail, as opposed to a mail that failed.
    expect($notice->emailed_at)->toBeNull()
        ->and($notice->mail_error)->toBeNull()
        ->and($notice->title)->toContain('NGN 300.00');

    Mail::assertNothingSent();
});

/*
| `Mail::fake()` records a mailable without rendering it, so every other test in
| this file passes on a template that does not exist, a view name with a typo in it,
| and a figure the template refers to under a name the mailable never passed it. The
| rendering is therefore asserted separately and directly: this is the test that
| fails if a mail would throw the moment a real transport tried to build it.
*/
test('every custody mail renders, with the money it is about on it', function () {
    pn48Platform();
    pn48Settings(['system_payment_processor' => 'bachs', 'company_name' => 'Storify']);

    [$business, $store] = pn48Business();

    $sale = pn48Funded($business, 150000, $store);

    pn48ProviderAccepts();

    app(PayoutSweepService::class)->sweep();

    $payout = pn48Payouts($business)->firstOrFail();
    $owner = $business->owner;

    $credited = (new WalletCreditedMail(
        user: $owner,
        amount: 'NGN 1,500.00',
        fee: 'NGN 50.00',
        storeName: $store->name,
        reference: $sale->reference,
        date: 'Tue, Oct 14, 2025 3:04 PM',
    ))->render();

    expect($credited)
        ->toContain('NGN 1,500.00')
        ->toContain($store->name)
        ->toContain('Processing fee')
        ->toContain('NGN 50.00')
        ->toContain('Storify')
        // The change itself, guarded: the first mail a business gets says what
        // happened and nothing about the split, the wallet or the clearing behind
        // it. These negatives are what a future edit has to get past.
        ->not->toContain('share')
        ->not->toContain('wallet')
        ->not->toContain('Settling')
        ->not->toContain('payable');

    // Marked `paid` so the paid mail renders the state it is written for.
    $payout->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => now()])->save();

    $paid = (new PayoutPaidMail($owner, $business, $payout->fresh(), 'Guaranty Trust Bank'))->render();

    expect($paid)
        ->toContain('NGN 1,500.00')
        ->toContain('Guaranty Trust Bank')
        ->toContain($payout->reference)
        // Masked: a payout mail is a record that money moved, and the full account
        // number in it is a number sitting in a mailbox forever. The last four are
        // what a person checks against their statement.
        ->toContain('6789')
        ->not->toContain('0123456789')
        ->not->toContain('wallet');

    $failed = (new PayoutFailedMail($owner, $business, $payout->fresh(), 'Guaranty Trust Bank'))->render();

    expect($failed)
        ->toContain('NGN 1,500.00')
        // Still the reassurance the mail exists for, in words that name nothing
        // the business does not have to think about.
        ->toContain('Nothing has been lost')
        ->not->toContain('wallet');

    $reversed = (new PayoutReversedMail($owner, $business, $payout->fresh(), 'Guaranty Trust Bank'))->render();

    expect($reversed)
        ->toContain('NGN 1,500.00')
        ->toContain('still owed this money')
        ->not->toContain('wallet');

    $blocked = (new PayoutBlockedMail(
        user: $owner,
        business: $business,
        walletBalanceKobo: 150000,
        bankName: 'Guaranty Trust Bank',
        bankCode: '058',
        accountNumber: '0123456789',
        accountName: 'Ada Trading Ltd',
        attempts: 3,
        lastReason: 'Account cannot receive transfers.',
        currency: 'NGN',
    ))->render();

    expect($blocked)
        ->toContain('NGN 1,500.00')
        ->toContain('3 times')
        ->toContain('Account cannot receive transfers.')
        ->toContain('Ada Trading Ltd')
        ->toContain('6789')
        ->not->toContain('0123456789')
        // The one mail that keeps the figure still keeps it without the word:
        // "Waiting to be paid to you" is the business's money, not a wallet entry.
        ->toContain('Waiting to be paid to you')
        ->not->toContain('wallet');
});
