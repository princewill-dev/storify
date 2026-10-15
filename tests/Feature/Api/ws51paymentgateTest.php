<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\KycApplication;
use App\Models\Store;
use App\Models\User;
use App\Services\Access\StorePaymentGate;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| WS-51 — the KYC gate, on the money rather than on the shop
|--------------------------------------------------------------------------
| Store activation used to refuse until the owner's KYC was approved, which
| made verification a gate on *building* a shop: no storefront, no products, no
| looking at what you had made. The check lives on the payment surfaces now, and
| this file pins the three states it can be in — approved, submitted, and no
| application at all — plus one surface end to end.
|
| The message is the owner's own copy and is the same string everywhere, so it
| is asserted rather than described.
*/

function pg51Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'PG51 Store',
        'slug' => 'pg51-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'pos_enabled' => true,
    ], $attributes));
}

test('an approved owner takes payments', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    expect((new KycApplication)->where('user_id', $owner->id)->first()->status)
        ->toBe(KycApplication::STATUS_APPROVED)
        ->and(app(StorePaymentGate::class)->allows(pg51Store($owner)))->toBeTrue();
});

test('an application under review does not, because submitted is not approved', function () {
    // The state an owner is in between answering the form and being verified.
    // Money must not move in it, which is the whole reason the gate is on the
    // payment rather than only on a screen.
    [$owner] = createBusinessOwner(['kyc' => 'submitted', 'trial_ends_at' => now()->addWeek()]);

    $gate = app(StorePaymentGate::class);

    expect($gate->allows(pg51Store($owner)))->toBeFalse()
        ->and($gate->refusal(pg51Store($owner)))->toBe(StorePaymentGate::REFUSAL);
});

test('a business with no application at all is refused', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none', 'trial_ends_at' => now()->addWeek()]);

    expect(app(StorePaymentGate::class)->refusal(pg51Store($owner)))->toBe(StorePaymentGate::REFUSAL);
});

test('the gate asks about the owner, not about whoever is acting', function () {
    // A manager on a verified business is not blocked by their own missing
    // application — the business is the thing being verified.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $manager = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    // The manager has no application of their own, and the store is the
    // business's rather than the manager's.
    expect(app(StorePaymentGate::class)->allows(pg51Store($owner)))->toBeTrue()
        ->and($manager->kycApplication)->toBeNull();
});

test('an unverified business is refused on a real payment surface', function () {
    // The gate's logic is pinned above. This is the wiring: the public invoice
    // page is the cheapest surface that actually starts a payment, and it is one
    // of the four the check sits on.
    [$owner, $business] = createBusinessOwner(['kyc' => 'none', 'trial_ends_at' => now()->addWeek()]);

    $store = pg51Store($owner);

    $invoice = Invoice::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'recipient_name' => 'Ada Client',
        'recipient_email' => 'ada@example.com',
        'status' => InvoiceStatus::SENT,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'subtotal' => 1000,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'discount_value' => 0,
        'total' => 1000,
        'payment_token' => Str::random(32),
    ]);

    $this->postJson("/pay/invoice/{$invoice->payment_token}/initialize", ['amount' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', StorePaymentGate::REFUSAL);

    // And approving the owner gets past the gate, so this is testing the KYC
    // check rather than some other refusal wearing its message. What the endpoint
    // does *after* the gate is the payment provider's business and is covered
    // where it belongs — asserting a 200 here would be asserting that a test
    // environment has a working gateway.
    // Created rather than updated: this owner was built with no application at
    // all, so there is no row to flip.
    $application = new KycApplication;
    $application->forceFill([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => KycApplication::STATUS_APPROVED,
        'legal_name' => 'Test Owner',
        'phone_number' => '08030000000',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'kyc_document_id' => 'NIN-123456789',
        'submitted_at' => now()->subDay(),
        'approved_at' => now(),
    ])->save();

    $after = $this->postJson("/pay/invoice/{$invoice->payment_token}/initialize", ['amount' => 1000]);

    expect($after->json('message'))->not->toBe(StorePaymentGate::REFUSAL);
});
