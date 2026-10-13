<?php

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Database\Seeders\PaymentMethodSeeder;

/*
| WS-51 — what a plan entitles a business to, and the screen that obeys it.
|
| `subscription_plans.features` is a list of display strings that no code reads,
| so a plan has never actually *contained* anything: every business, on every
| plan, could create the same number of stores and reach the same providers.
| These tests pin the replacement — a structured `entitlements` map with one
| reader, {@see Entitlements} — and the first rule built on it: connecting your
| own payment gateway belongs to the top plan, or to a business an admin has
| granted it to individually.
|
| The resolution order is the part worth reading twice. A subscription beats a
| trial, a trial follows the plan the owner picked, and a trial that never picked
| one falls back to the platform's default plan rather than to nothing — which is
| the difference between a trial that can use the product and a trial that cannot
| create a single store.
*/

function ws51Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws51Plan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::create(array_merge([
        'name' => 'Entry',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'entitlements' => [
            EntitlementRegistry::STORES => 3,
            EntitlementRegistry::OWN_GATEWAY => false,
        ],
        'sort_order' => 1,
    ], $attributes));
}

function ws51Enterprise(): SubscriptionPlan
{
    return ws51Plan([
        'name' => 'Enterprise',
        'amount' => 50000,
        'tier' => 3,
        'entitlements' => [
            // Null is unlimited, and it is the whole of what this tier sells.
            EntitlementRegistry::STORES => null,
            EntitlementRegistry::OWN_GATEWAY => true,
        ],
        'sort_order' => 3,
    ]);
}

function ws51Subscribe(User $user, SubscriptionPlan $plan): Subscription
{
    return Subscription::create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'subscription_plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subWeek(),
        'expires_at' => now()->addMonth(),
    ]);
}

function ws51Payload(): array
{
    return [
        'is_enabled' => true,
        'config' => ['public_key' => 'pk_live_ws51abcdefgh', 'secret_key' => 'sk_live_ws51abcdefgh'],
    ];
}

beforeEach(function () {
    $this->seed(PaymentMethodSeeder::class);
});

/* ------------------------------------------------------------ resolution */

test('a paid plan governs over the trial that preceded it', function () {
    // The order that matters after a conversion. A business that has started
    // paying is governed by what it bought, not by the trial it came through —
    // otherwise a trial of the top tier would still be granting its
    // entitlements to a business now paying for the entry tier.
    $entry = ws51Plan();
    $enterprise = ws51Enterprise();

    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'selected_plan_id' => $enterprise->id,
    ]);

    ws51Subscribe($owner, $entry);

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->source())->toBe(Entitlements::SOURCE_SUBSCRIPTION)
        ->and($entitlements->plan()->name)->toBe('Entry')
        ->and($entitlements->allows(EntitlementRegistry::OWN_GATEWAY))->toBeFalse()
        ->and($entitlements->limit(EntitlementRegistry::STORES))->toBe(3);
});

test('a trial grants the plan the owner picked, enterprise included', function () {
    // A trial is a trial *of a plan*. Picking the top tier and trialling it must
    // reach the same screens the paid version does, or the trial is of nothing.
    $enterprise = ws51Enterprise();

    [$owner, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
        'selected_plan_id' => $enterprise->id,
    ]);

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->source())->toBe(Entitlements::SOURCE_TRIAL)
        ->and($entitlements->allows(EntitlementRegistry::OWN_GATEWAY))->toBeTrue()
        // Unlimited, not zero — the distinction the top tier rests on.
        ->and($entitlements->limit(EntitlementRegistry::STORES))->toBeNull();
});

test('a trial that never picked a plan falls back to the platform default', function () {
    // The ordinary state of a business in its first week, and not a corner case.
    // Answering "no entitlements" here would take away the stores a trial exists
    // to let somebody try.
    $default = ws51Plan([
        'name' => 'Default',
        'is_default' => true,
        'entitlements' => [EntitlementRegistry::STORES => 5, EntitlementRegistry::OWN_GATEWAY => false],
    ]);

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->source())->toBe(Entitlements::SOURCE_TRIAL)
        ->and($entitlements->plan()->id)->toBe($default->id)
        ->and($entitlements->limit(EntitlementRegistry::STORES))->toBe(5);
});

test('no plan and no live trial entitles a business to nothing', function () {
    // Fail closed. `EnsureManagementSubscription` already keeps this business
    // off `/management/*`, so this is the belt to that pair of braces — and the
    // safe direction, because a limit read as unlimited is a lapsed business
    // running without a cap.
    [$owner, $business] = createBusinessOwner();

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->source())->toBe(Entitlements::SOURCE_NONE)
        ->and($entitlements->isResolved())->toBeFalse()
        ->and($entitlements->allows(EntitlementRegistry::OWN_GATEWAY))->toBeFalse()
        ->and($entitlements->limit(EntitlementRegistry::STORES))->toBe(0);
});

test('an expired trial entitles a business to nothing either', function () {
    // `trial_ends_at` in the past is the state the expiry job leaves behind, and
    // it must not keep granting what the trial granted.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->subDay()]);

    expect(Entitlements::for($business->fresh())->source())->toBe(Entitlements::SOURCE_NONE);
});

test('a plan that states nothing falls back to the registry defaults', function () {
    // Every plan that existed before the `entitlements` column did. The
    // migration is additive precisely so that this resolves rather than breaking,
    // and the default for the gateway entitlement is off — which is the
    // platform's position, not an accident of the fallback.
    $legacy = ws51Plan(['name' => 'Legacy', 'entitlements' => null]);

    [$owner, $business] = createBusinessOwner();
    ws51Subscribe($owner, $legacy);

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->allows(EntitlementRegistry::OWN_GATEWAY))->toBeFalse()
        ->and($entitlements->limit(EntitlementRegistry::STORES))
        ->toBe(EntitlementRegistry::defaultFor(EntitlementRegistry::STORES));
});

test('reaching a limit is inclusive, so a grandfathered business is capped', function () {
    // `reached()` is `<= 0`, not `=== 0`: a business that dropped to a smaller
    // plan while holding more stores than it allows keeps every store it has and
    // may not add another. Tightening a rule must never take anything away.
    $entry = ws51Plan();

    [$owner, $business] = createBusinessOwner();
    ws51Subscribe($owner, $entry);

    $entitlements = Entitlements::for($business->fresh());

    expect($entitlements->reached(EntitlementRegistry::STORES, 2))->toBeFalse()
        ->and($entitlements->reached(EntitlementRegistry::STORES, 3))->toBeTrue()
        ->and($entitlements->reached(EntitlementRegistry::STORES, 9))->toBeTrue()
        // Unlimited never reaches, whatever is held against it.
        ->and(Entitlements::none()->reached(EntitlementRegistry::STORES, 9))->toBeTrue();
});

/* ------------------------------------------------------ the registry bound */

test('the registry drops keys it does not know and coerces the ones it does', function () {
    // The boundary that keeps a plan's JSON honest. A key left behind by an
    // entitlement that no longer exists would otherwise sit in the column
    // forever, read by nothing and confusing everyone who looks at it.
    $normalized = EntitlementRegistry::normalize([
        EntitlementRegistry::STORES => '7',
        EntitlementRegistry::OWN_GATEWAY => '1',
        'not_an_entitlement' => 'anything',
    ]);

    expect($normalized)->toBe([
        EntitlementRegistry::STORES => 7,
        EntitlementRegistry::OWN_GATEWAY => true,
    ]);
});

test('a blank limit is unlimited, and not zero', function () {
    // The single most expensive mistake available in this area: reading an empty
    // box as zero turns the plan that grants everything into the plan that
    // cannot create one store.
    expect(EntitlementRegistry::normalize([EntitlementRegistry::STORES => '']))
        ->toBe([EntitlementRegistry::STORES => null])
        ->and(EntitlementRegistry::normalize([EntitlementRegistry::STORES => null]))
        ->toBe([EntitlementRegistry::STORES => null]);
});

test('a limit that is not a usable number is dropped rather than guessed at', function () {
    // Dropped, so the registry default applies — rather than a typo becoming a
    // cap of zero, or a negative number becoming a cap nobody can reason about.
    expect(EntitlementRegistry::normalize([EntitlementRegistry::STORES => -4]))
        ->toBe([])
        ->and(EntitlementRegistry::normalize([EntitlementRegistry::STORES => 'many']))
        ->toBe([]);
});

test('the catalogue the admin editor renders describes every entitlement', function () {
    // The editor is built from this, so a new entitlement appears in the admin
    // console with no frontend change — the same contract
    // PaymentGatewayRegistry has with the payments screen.
    $catalogue = collect(EntitlementRegistry::cataloguePayload())->keyBy('key');

    expect($catalogue->keys()->all())->toBe(EntitlementRegistry::keys())
        ->and($catalogue[EntitlementRegistry::OWN_GATEWAY]['type'])->toBe(EntitlementRegistry::TYPE_TOGGLE)
        ->and($catalogue[EntitlementRegistry::STORES]['type'])->toBe(EntitlementRegistry::TYPE_LIMIT)
        // The browser has no business holding anything but labels and shapes.
        ->and($catalogue[EntitlementRegistry::STORES])->toHaveKeys(['key', 'label', 'help', 'type', 'default', 'unit']);
});

/* --------------------------------------------- the payment settings gate */

test('the enterprise plan reaches the catalogue without an admin grant', function () {
    // The point of the whole change: buying the top plan is enough. Before this
    // the only route in was support, so the ability could not be sold.
    [$owner, $business] = createBusinessOwner();
    ws51Subscribe($owner, ws51Enterprise());

    expect($business->fresh()->can_connect_own_gateway)->toBeFalsy();

    $this->withToken(ws51Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', ws51Payload())
        ->assertOk();

    expect(DB::table('business_payment_method')->where('business_id', $business->id)->count())->toBe(1);
});

test('an entry plan is refused, and the refusal names the plan that would allow it', function () {
    // Named from the plans table rather than hard-coded, because every plan's
    // name is the admin's to change — and a business told "no" with no route
    // forward is a business that goes looking for a way round.
    [$owner, $business] = createBusinessOwner();
    ws51Subscribe($owner, ws51Plan());
    ws51Enterprise();

    $this->withToken(ws51Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', ws51Payload())
        ->assertStatus(422)
        ->assertJsonPath('errors.config.0', function (string $message): bool {
            return str_contains($message, 'Enterprise plan');
        });

    expect(DB::table('business_payment_method')->where('business_id', $business->id)->count())->toBe(0);
});

test('an entry plan is shown the bank rail and nothing else', function () {
    // The screen the owner asked for. The strip is the server's, so the page
    // cannot offer a provider the write path would refuse — and the bank rail
    // survives it structurally, because it is provisioned from the business's
    // own bank accounts rather than from API keys.
    [$owner] = createBusinessOwner();
    ws51Subscribe($owner, ws51Plan());
    // Present so the refusal has a plan to name. Without one the sentence has to
    // fall back to "ask support", which is a case with its own test below.
    ws51Enterprise();

    $response = $this->withToken(ws51Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk()
        ->assertJsonPath('data.gateway_access.allowed', false);

    expect(collect($response->json('data.catalogue'))->pluck('code')->all())
        ->toBe(['bank_transfer'])
        ->and($response->json('data.gateway_access.reason'))->toContain('Enterprise plan')
        ->and($response->json('data.gateway_access.upgrade_url'))->toBe('/plans');
});

test('the enterprise plan is shown the whole catalogue', function () {
    [$owner] = createBusinessOwner();
    ws51Subscribe($owner, ws51Enterprise());

    $response = $this->withToken(ws51Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk()
        ->assertJsonPath('data.gateway_access.allowed', true)
        ->assertJsonPath('data.gateway_access.reason', null);

    $codes = collect($response->json('data.catalogue'))->pluck('code');

    expect($codes)->toContain('bank_transfer', 'paystack', 'flutterwave')
        ->and($codes->count())->toBeGreaterThan(5);
});

test('the manual grant still reaches the catalogue on a plan that does not include it', function () {
    // The other route in, and it has to keep working: comped accounts, a
    // migration, a business mid-upgrade that needs to trade today. A grant that
    // did not survive an entry plan would be a grant that did nothing.
    [$owner, $business] = createBusinessOwner();
    ws51Subscribe($owner, ws51Plan());

    DB::table('businesses')->where('id', $business->id)->update(['can_connect_own_gateway' => true]);

    $this->withToken(ws51Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk()
        ->assertJsonPath('data.gateway_access.allowed', true);

    $this->withToken(ws51Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', ws51Payload())
        ->assertOk();
});

test('a business downgraded off enterprise keeps the connection it already had', function () {
    // Dropping to a smaller plan must not strand a live connection: still
    // charging at checkout, and with no screen left that admits it exists. The
    // business keeps it, sees it, and can still take it apart.
    [$owner, $business] = createBusinessOwner();
    $enterprise = ws51Enterprise();
    $subscription = ws51Subscribe($owner, $enterprise);

    $this->withToken(ws51Token($owner))
        ->putJson('/api/v1/management/payment-gateways/paystack', ws51Payload())
        ->assertOk();

    $entry = ws51Plan();
    $subscription->update(['subscription_plan_id' => $entry->id]);

    $response = $this->withToken(ws51Token($owner))
        ->getJson('/api/v1/management/payment-gateways')
        ->assertOk()
        ->assertJsonPath('data.gateway_access.allowed', false);

    // Gone from the menu they may connect from...
    expect(collect($response->json('data.catalogue'))->pluck('code')->all())->toBe(['bank_transfer']);

    // ...and still present in the state of their own account.
    $paystack = collect($response->json('data.providers'))->firstWhere('code', 'paystack');

    expect($paystack)->not->toBeNull()
        ->and($paystack['has_connection'])->toBeTrue()
        ->and($paystack['can_connect_own_gateway'])->toBeFalse();

    // And it can still be disconnected, which is the half that would have been
    // lost if the payload had simply hidden it.
    $this->withToken(ws51Token($owner))
        ->deleteJson('/api/v1/management/payment-gateways/paystack')
        ->assertOk();

    expect(DB::table('business_payment_method')->where('business_id', $business->id)->count())->toBe(0);
});

test('the bank rail is never gated, on any plan', function () {
    // `usesOwnKeys()` gates providers provisioned with API keys, and bank
    // transfer is provisioned with the business's own bank accounts. So it needs
    // no exemption anywhere — and a business told to connect a bank account
    // instead must not find that rail closed too.
    [$owner] = createBusinessOwner();
    ws51Subscribe($owner, ws51Plan());

    $providers = collect(
        $this->withToken(ws51Token($owner))
            ->getJson('/api/v1/management/payment-gateways')
            ->assertOk()
            ->json('data.providers')
    )->keyBy('code');

    expect($providers)->toHaveKey('bank_transfer')
        ->and($providers['bank_transfer']['can_connect_own_gateway'])->toBeFalse();
});
