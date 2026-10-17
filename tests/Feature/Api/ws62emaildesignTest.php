<?php

use App\Enums\TransactionStatus;
use App\Mail\AdminPayoutsStalledMail;
use App\Mail\AdminPayoutUnconfirmedMail;
use App\Mail\BusinessKycSubmitted;
use App\Mail\KycApproved;
use App\Mail\PaymentConfirmedMail;
use App\Mail\PayoutBlockedMail;
use App\Mail\PayoutFailedMail;
use App\Mail\PayoutPaidMail;
use App\Mail\PayoutReversedMail;
use App\Mail\RefundProcessedMail;
use App\Mail\StaffInvitationMail;
use App\Mail\WalletCreditedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\EmailBrand;
use App\Support\SpaUrls;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| WS-62 — one letterhead for every email the platform sends.
|
| Before this there was no shared shell: the fifty-four templates fell into five
| unrelated visual families, from markdown KYC mail and `display:flex` receipts to
| bare plain-text invitations. The owner asked for one design, borrowed from a
| payment receipt and carrying Cloudflare's restraint — uppercase micro-labels,
| thin rules, flat panels and whitespace — with Storify blue as the only accent.
|
| Two things are worth testing here, and they are different:
|
| **Coverage.** The design is only a design if every template uses it. A missed
| file is invisible in review but obvious in an inbox, so the first test walks the
| directory and fails on any template that has not moved onto `<x-email.layout>`.
|
| **Copy.** Restyling is where fixed, load-bearing sentences get lost. The money
| mails carry reassurances ("Nothing has been lost", "still owed this money") that
| must survive the redesign, and the admin unconfirmed alert carries an instruction
| whose opposite ("Retry sends it again immediately") is true elsewhere and
| expensive here. Those are asserted against the rendered HTML, not trusted to a
| reviewer.
|
| Branding is asserted separately: a customer's receipt carries the store, with a
| small "Powered by" line beneath it; the platform's own copy does not.
*/

function ed62Store(User $owner, $business, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Ada Threads',
        'slug' => 'ed62-'.Str::lower(Str::random(8)),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
        'support_email' => 'help@adathreads.test',
        'support_phone' => '08012345678',
        'address' => '12 Marina, Lagos',
    ], $attributes));
}

function ed62Customer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'email' => 'ada-'.Str::lower(Str::random(6)).'@example.test',
        'phone' => '08011112222',
        'password' => bcrypt('secret-pass-123'),
        'status' => 'ACTIVE',
    ], $attributes));
}

function ed62Order(Store $store, array $attributes = []): Order
{
    return Order::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'source' => 'checkout',
        'order_number' => 'WS62-ORD-'.Str::upper(Str::random(6)),
        'subtotal' => 1250,
        'shipping_fee' => 0,
        'tax' => 0,
        'total' => 1250,
        'amount_paid' => 0,
        'status' => 'pending',
    ], $attributes));
}

function ed62Transaction(array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'reference' => 'WS62-TXN-'.Str::upper(Str::random(8)),
        'amount' => 1250,
        'currency' => 'NGN',
        'status' => TransactionStatus::CONFIRMED,
        'paid_at' => now(),
    ], $attributes));
}

function ed62Payout($business, array $attributes = []): Payout
{
    return Payout::create(array_merge([
        'business_id' => $business->id,
        'provider' => 'bachs',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'account_name' => 'Ada Trading Ltd',
        'amount_kobo' => 150000,
        'currency' => 'NGN',
        'status' => Payout::STATUS_PROCESSING,
        'reference' => 'WS62-PO-'.Str::upper(Str::random(8)),
        'requested_at' => now(),
        'sent_at' => now(),
    ], $attributes));
}

// ---------------------------------------------------------------------------
// Coverage
// ---------------------------------------------------------------------------

test('every email template renders through the shared layout', function () {
    $templates = collect(File::allFiles(resource_path('views/emails')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'));

    $unconverted = $templates
        ->reject(fn ($file): bool => str_contains(File::get($file->getPathname()), '<x-email.layout'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($unconverted)->toBe([]);

    // The old shells were built on flex rows, which Outlook drops on the floor.
    // A template that still carries one has been copied back from an old file.
    $flex = $templates
        ->filter(fn ($file): bool => str_contains(File::get($file->getPathname()), 'display:flex'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($flex)->toBe([]);
});

// ---------------------------------------------------------------------------
// The shell
// ---------------------------------------------------------------------------

test('a payment confirmation carries the new letterhead and the order facts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ed62Store($owner, $business);
    $customer = ed62Customer($business->id);
    $order = ed62Order($store, [
        'customer_id' => $customer->id,
        'order_number' => 'WS62-1001',
    ]);
    $transaction = ed62Transaction([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'reference' => 'WS62-PAY-1001',
    ]);

    $html = (new PaymentConfirmedMail($transaction, $order, $customer, $store))->render();

    expect($html)
        ->toContain('Payment confirmed')
        ->toContain('WS62-1001')
        ->toContain('1,250.00')
        ->toContain('WS62-PAY-1001')
        // The accent the whole design is built around.
        ->toContain('2563EB')
        ->toContain('<!doctype html>')
        ->not->toContain('display:flex');
});

test('the customer copy is store-branded and the owner copy is not', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ed62Store($owner, $business);
    $customer = ed62Customer($business->id);
    $order = ed62Order($store, ['customer_id' => $customer->id]);
    $transaction = ed62Transaction([
        'business_id' => $business->id,
        'order_id' => $order->id,
    ]);

    $forCustomer = (new PaymentConfirmedMail($transaction, $order, $customer, $store))->render();

    expect($forCustomer)
        ->toContain($store->name)
        ->toContain('Powered by')
        ->toContain(SpaUrls::home());

    $forOwner = (new PaymentConfirmedMail($transaction, $order, $owner, $store))->render();

    // The store owner is reading the platform's alert about their own shop, not
    // a receipt from the shop, so there is nothing to attribute to Storify.
    expect($forOwner)
        ->not->toContain('Powered by')
        ->toContain('View transaction');
});

// ---------------------------------------------------------------------------
// Money mail — the fixed sentences
// ---------------------------------------------------------------------------

test('the wallet credit keeps its copy, and only names a fee when one was taken', function () {
    [$owner] = createBusinessOwner();

    $withFee = (new WalletCreditedMail(
        user: $owner,
        amount: 'NGN 1,500.00',
        fee: 'NGN 50.00',
        storeName: 'Ada Threads',
        reference: 'WS62-CREDIT',
        date: 'Tue, Oct 14, 2025 3:04 PM',
    ))->render();

    expect($withFee)
        ->toContain('Payment details')
        ->toContain('Processing fee')
        ->toContain('NGN 1,500.00')
        ->toContain('NGN 50.00')
        ->toContain('2563EB')
        // The business is told what landed, not how the platform runs its money.
        ->not->toContain('share')
        ->not->toContain('wallet')
        ->not->toContain('Settling')
        ->not->toContain('payable');

    $withoutFee = (new WalletCreditedMail(
        user: $owner,
        amount: 'NGN 900.00',
        storeName: 'Ada Threads',
    ))->render();

    expect($withoutFee)
        ->toContain('NGN 900.00')
        ->not->toContain('Processing fee');
});

test('the payout mails keep their reassurance and never print a full account number', function () {
    [$owner, $business] = createBusinessOwner();
    $payout = ed62Payout($business, [
        'status' => Payout::STATUS_PAID,
        'paid_at' => now(),
        'failure_code' => 'ACCOUNT_NAME_MISMATCH',
        'failure_reason' => 'Account name does not match the account number.',
    ]);

    $paid = (new PayoutPaidMail($owner, $business, $payout, 'Guaranty Trust Bank'))->render();

    expect($paid)
        ->toContain('NGN 1,500.00')
        ->toContain('Guaranty Trust Bank')
        ->toContain($payout->reference)
        ->toContain('6789')
        ->not->toContain('0123456789')
        ->not->toContain('wallet');

    $failed = (new PayoutFailedMail($owner, $business, $payout, 'Guaranty Trust Bank'))->render();

    expect($failed)
        ->toContain('NGN 1,500.00')
        ->toContain('Nothing has been lost')
        ->not->toContain('wallet');

    $reversed = (new PayoutReversedMail($owner, $business, $payout, 'Guaranty Trust Bank'))->render();

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
        ->toContain('Waiting to be paid to you')
        ->toContain('3 times')
        ->toContain('Account cannot receive transfers.')
        ->toContain('Ada Trading Ltd')
        ->toContain('6789')
        ->not->toContain('0123456789');
});

test('the admin alerts say the opposite things, because their facts are opposite', function () {
    [$owner, $business] = createBusinessOwner();

    $unconfirmedPayout = ed62Payout($business, [
        'failure_reason' => null,
        'meta' => ['last_send' => ['status' => 'initiated']],
    ]);

    $unconfirmed = (new AdminPayoutUnconfirmedMail(
        business: $business,
        payout: $unconfirmedPayout,
        checks: 9,
        since: now()->subMinutes(30),
        reviewUrl: SpaUrls::admin('/payouts'),
    ))->render();

    expect($unconfirmed)
        ->toContain('Do not resend this transfer yet')
        ->toContain('The money is out of the wallet and we cannot confirm where it went')
        ->toContain($unconfirmedPayout->reference)
        ->toContain('initiated')
        // The stalled mail's reassurance is false for a transfer that may have
        // landed, and its retry advice is how a business gets paid twice.
        ->not->toContain('Retry sends it again immediately')
        ->not->toContain('never left their wallet');

    $stalledPayout = ed62Payout($business, [
        'status' => Payout::STATUS_FAILED,
        'failure_code' => 'ACCOUNT_NAME_MISMATCH',
        'failure_reason' => 'Account name does not match the account number.',
    ]);

    $stalled = (new AdminPayoutsStalledMail(
        business: $business,
        payout: $stalledPayout,
        blocker: ['code' => 'destination_failed', 'label' => 'The bank keeps refusing the destination'],
        failures: 3,
        heldKobo: 150000,
        reviewUrl: SpaUrls::admin('/payouts'),
    ))->render();

    expect($stalled)
        ->toContain('Retry sends it again immediately')
        ->toContain('The bank keeps refusing the destination')
        ->toContain('1,500.00')
        ->toContain('ACCOUNT_NAME_MISMATCH')
        ->toContain('Account name does not match the account number.');
});

// ---------------------------------------------------------------------------
// KYC, branding, invitations
// ---------------------------------------------------------------------------

test('the KYC mails keep the acknowledgements and the masking rules', function () {
    [$owner] = createBusinessOwner();
    $application = $owner->kycApplication()->firstOrFail();

    $submitted = (new BusinessKycSubmitted($owner, $application))->render();

    expect($submitted)
        ->toContain('we’ve received your KYC submission')
        ->toContain('2563EB')
        // The business acknowledgement must not become a copy of the identifier.
        ->not->toContain('Document type');

    $approved = (new KycApproved($owner, $application))->render();

    expect($approved)
        ->toContain('KYC verification approved')
        ->toContain('2563EB');
});

test('a refund is store-branded and points back to the platform home', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ed62Store($owner, $business);
    $customer = ed62Customer($business->id);
    $order = ed62Order($store, ['customer_id' => $customer->id]);
    $transaction = ed62Transaction([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'status' => TransactionStatus::REFUNDED,
        'reference' => 'WS62-REFUND',
    ]);

    $html = (new RefundProcessedMail(
        $transaction,
        $order,
        $customer,
        $store,
        'Item never arrived',
    ))->render();

    expect($html)
        ->toContain($store->name)
        ->toContain('help@adathreads.test')
        ->toContain('Powered by')
        ->toContain(SpaUrls::home())
        ->toContain('Item never arrived');
});

test('the staff invitation is HTML now and still carries the credentials', function () {
    [$owner] = createBusinessOwner();
    $owner->forceFill(['invitation_token' => 'ws62-invite-'.Str::random(12)])->save();

    $html = (new StaffInvitationMail($owner->fresh(), 'TempPass-123'))->render();

    expect($html)
        ->toContain('<!doctype html>')
        ->toContain(SpaUrls::management('/invitations/'.$owner->invitation_token))
        ->toContain('TempPass-123')
        ->toContain('2563EB')
        ->not->toContain('display:flex');
});

test('with no settings row the brand falls back instead of throwing', function () {
    Setting::query()->delete();

    $brand = EmailBrand::resolve();

    expect($brand['logo'])->toBe(asset('logo.png'))
        ->and($brand['email'])->toBe(config('mail.from.address'))
        ->and($brand['name'])->toBe(config('app.name'));

    // The synchronous money alerts render without a settings row; this is the
    // path a worker takes before the platform has been configured.
    $owner = User::factory()->create(['name' => 'Ada Owner']);

    $html = (new WalletCreditedMail(
        user: $owner,
        amount: 'NGN 100.00',
    ))->render();

    expect($html)
        ->toContain('NGN 100.00')
        ->toContain('2563EB');
});
