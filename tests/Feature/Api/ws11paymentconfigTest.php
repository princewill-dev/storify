<?php

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| WS-11 — Payment configuration (banks, Paystack, store assignment).
|
| Covers the main flows, the validation failures and the cross-tenant
| refusals: a bank, a gateway or a store from another business must never be
| readable or mutable, and the Paystack secret must never leave the API.
*/

function ws11Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws11Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ws11-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

function ws11Bank(User $owner, array $attributes = []): StoreBank
{
    return StoreBank::create(array_merge([
        'business_id' => $owner->business_id,
        'bank_name' => 'GTBank',
        'bank_code' => '058',
        'account_number' => (string) fake()->unique()->numerify('##########'),
        'account_name' => 'ACME LTD',
        'is_primary' => false,
        'is_verified' => true,
    ], $attributes));
}

/** Insert a connected Paystack gateway and return its pivot id. */
function ws11ConnectPaystack(User $owner, array $config = []): int
{
    $method = PaymentMethod::where('code', 'paystack')->firstOrFail();

    return (int) DB::table('business_payment_method')->insertGetId([
        'business_id' => $owner->business_id,
        'payment_method_id' => $method->id,
        'is_active' => true,
        'config' => json_encode(array_merge([
            'public_key' => 'pk_test_1234567890abcd',
            'secret_key' => 'sk_test_1234567890abcd',
        ], $config)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('payment settings lists banks masked and gateways without the secret key', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    ws11Bank($owner, ['account_number' => '0123456789', 'is_primary' => true]);
    ws11ConnectPaystack($owner);

    $response = $this->withToken(ws11Token($owner))->getJson('/api/v1/management/payment-settings');

    $response->assertOk()
        ->assertJsonPath('data.banks.0.bank_name', 'GTBank')
        ->assertJsonPath('data.gateways.0.code', 'paystack')
        ->assertJsonPath('data.gateways.0.has_secret_key', true)
        ->assertJsonPath('data.gateways.0.public_key_masked', 'pk_test****abcd');

    // The browser sees a masked number, never the stored one.
    expect($response->json('data.banks.0.masked_account_number'))->not->toBe('0123456789');
    expect($response->json('data.banks.0.masked_account_number'))->toContain('*');

    // Legacy round-tripped the secret key to the browser; the rebuilt API must not.
    expect(json_encode($response->json()))->not->toContain('sk_test_1234567890abcd');
});

test('a business without the settings payment permission cannot read payment settings', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    $this->withToken(ws11Token($staff))
        ->getJson('/api/v1/management/payment-settings')
        ->assertStatus(403);

    // The Sanctum guard caches the first user it resolves for the lifetime of
    // a test, so the token switch needs a forget or the owner would still be
    // authenticated as the staff user above.
    app('auth')->forgetGuards();

    $this->withToken(ws11Token($owner))
        ->getJson('/api/v1/management/payment-settings')
        ->assertOk();
});

test('a duplicate bank account is refused within the business', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $payload = [
        'bank_code' => '058',
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'account_name' => 'ACME LTD',
    ];

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', $payload)
        ->assertCreated();

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'This bank account already exists.');

    // A different business adding its own account is unaffected by the
    // first business's rows. The Sanctum guard caches the first user it
    // resolved, so it must be forgotten before acting as the other owner.
    app('auth')->forgetGuards();

    $this->withToken(ws11Token($otherOwner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', [
            'bank_code' => '058',
            'bank_name' => 'GTBank',
            'account_number' => '0987654321',
            'account_name' => 'OTHER LTD',
        ])
        ->assertCreated();

    $this->withToken(ws11Token($otherOwner))
        ->getJson('/api/v1/management/payment-settings')
        ->assertOk()
        ->assertJsonCount(1, 'data.banks')
        ->assertJsonPath('data.banks.0.account_name', 'OTHER LTD');
});

test('a bank account requires a 10-digit account number and a resolved name', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', [
            'bank_code' => '058',
            'bank_name' => 'GTBank',
            'account_number' => '12345',
            'account_name' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_number', 'account_name']);
});

test('verify-bank resolves the account name through Paystack and surfaces failures', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    // One sequence for both Paystack calls: a second Http::fake() only
    // appends a stub, so the first '*' stub would keep answering.
    Http::fakeSequence('*')
        ->push([
            'status' => true,
            'data' => ['account_number' => '0123456789', 'account_name' => 'JANE DOE'],
        ])
        // Paystack answers HTTP 200 with status=false when it cannot resolve.
        ->push(['status' => false, 'message' => 'Could not resolve account name']);

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/verify-bank', [
            'account_number' => '0123456789',
            'bank_code' => '058',
        ])
        ->assertOk()
        ->assertJsonPath('data.account_name', 'JANE DOE');

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/verify-bank', [
            'account_number' => '0123456789',
            'bank_code' => '058',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Could not resolve account name');

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/verify-bank', [
            'account_number' => '123',
            'bank_code' => '058',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_number');
});

test('the paystack bank list is proxied for the add-account modal', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    Http::fake([
        '*' => Http::response([
            'status' => true,
            'data' => [
                ['name' => 'GTBank', 'code' => '058', 'active' => true],
                ['name' => 'Old Bank', 'code' => '999', 'active' => false],
            ],
        ]),
    ]);

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/banks')
        ->assertOk()
        ->assertJsonPath('data.banks.0.name', 'GTBank')
        ->assertJsonCount(1, 'data.banks');
});

test('the first bank becomes primary and switches bank transfer on for the business and its store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    $response = $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/bank-accounts', [
            'bank_code' => '058',
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'ACME LTD',
            'store_id' => $store->id,
        ])
        ->assertCreated();

    expect($response->json('data.bank.is_primary'))->toBeTrue();

    $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    // Business-level method auto-registered, config carries the account.
    $pivot = DB::table('business_payment_method')
        ->where('business_id', $owner->business_id)
        ->where('payment_method_id', $bankTransfer->id)
        ->first();
    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->is_active)->toBeTrue()
        ->and($pivot->config)->toContain('0123456789');

    // The bank and the method are assigned to the chosen store.
    expect(DB::table('store_bank')->where('store_id', $store->id)->count())->toBe(1);
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeTrue();
});

test('setting another bank primary clears the previous one', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $first = ws11Bank($owner, ['is_primary' => true, 'account_number' => '0111111111']);
    $second = ws11Bank($owner, ['is_primary' => false, 'account_number' => '0222222222']);

    $this->withToken(ws11Token($owner))
        ->patchJson("/api/v1/management/payment-settings/bank-accounts/{$second->id}/primary")
        ->assertOk();

    expect($first->fresh()->is_primary)->toBeFalse();
    expect($second->fresh()->is_primary)->toBeTrue();
});

test('a bank account can be renamed and promoted through the update endpoint', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $first = ws11Bank($owner, ['is_primary' => true, 'account_number' => '0111111111']);
    $second = ws11Bank($owner, ['is_primary' => false, 'account_number' => '0222222222']);

    // A plain rename must not silently demote the primary account.
    $this->withToken(ws11Token($owner))
        ->putJson("/api/v1/management/payment-settings/bank-accounts/{$second->id}", ['account_name' => 'RENAMED LTD'])
        ->assertOk()
        ->assertJsonPath('data.bank.account_name', 'RENAMED LTD');

    expect($second->fresh()->account_name)->toBe('RENAMED LTD');
    expect($first->fresh()->is_primary)->toBeTrue();

    // Asking for primary explicitly moves it and clears the previous holder.
    $this->withToken(ws11Token($owner))
        ->putJson("/api/v1/management/payment-settings/bank-accounts/{$second->id}", [
            'account_name' => 'RENAMED LTD',
            'is_primary' => true,
        ])
        ->assertOk();

    expect($second->fresh()->is_primary)->toBeTrue();
    expect($first->fresh()->is_primary)->toBeFalse();
});

test('the primary bank cannot be deleted and a secondary one can', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $primary = ws11Bank($owner, ['is_primary' => true, 'account_number' => '0111111111']);
    $secondary = ws11Bank($owner, ['is_primary' => false, 'account_number' => '0222222222']);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/bank-accounts/{$primary->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete the primary bank account. Set another account as primary first.');

    expect($primary->fresh())->not->toBeNull();

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/bank-accounts/{$secondary->id}")
        ->assertOk();

    expect(StoreBank::find($secondary->id))->toBeNull();
});

test('a bank belonging to another business cannot be updated, promoted or deleted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws11Bank($otherOwner, ['account_number' => '0999999999']);

    $token = ws11Token($owner);

    $this->withToken($token)
        ->putJson("/api/v1/management/payment-settings/bank-accounts/{$theirs->id}", ['account_name' => 'Hacked'])
        ->assertStatus(403);

    $this->withToken($token)
        ->patchJson("/api/v1/management/payment-settings/bank-accounts/{$theirs->id}/primary")
        ->assertStatus(403);

    $this->withToken($token)
        ->deleteJson("/api/v1/management/payment-settings/bank-accounts/{$theirs->id}")
        ->assertStatus(403);

    expect($theirs->fresh()->account_name)->toBe('ACME LTD');
});

test('a paystack gateway can be connected, edited, toggled, tested and removed', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $created = $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/gateways', [
            'public_key' => 'pk_test_1234567890abcd',
            'secret_key' => 'sk_test_1234567890abcd',
        ])
        ->assertCreated();

    $gatewayId = $created->json('data.gateway.id');

    expect(json_encode($created->json()))->not->toContain('sk_test_1234567890abcd');

    // Connecting twice is refused — edit the existing keys instead.
    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-settings/gateways', [
            'public_key' => 'pk_test_other',
            'secret_key' => 'sk_test_other',
        ])
        ->assertStatus(422);

    // Public key changes, blank secret keeps the stored one (write-only).
    $this->withToken(ws11Token($owner))
        ->putJson("/api/v1/management/payment-settings/gateways/{$gatewayId}", [
            'public_key' => 'pk_test_updatedkey',
        ])
        ->assertOk();

    $config = json_decode(DB::table('business_payment_method')->where('id', $gatewayId)->value('config'), true);
    expect($config['public_key'])->toBe('pk_test_updatedkey');
    expect($config['secret_key'])->toBe('sk_test_1234567890abcd');

    // A new secret replaces the old one.
    $this->withToken(ws11Token($owner))
        ->putJson("/api/v1/management/payment-settings/gateways/{$gatewayId}", [
            'public_key' => 'pk_test_updatedkey',
            'secret_key' => 'sk_test_replaced',
        ])
        ->assertOk();

    expect(json_decode(DB::table('business_payment_method')->where('id', $gatewayId)->value('config'), true)['secret_key'])
        ->toBe('sk_test_replaced');

    // Toggle.
    $this->withToken(ws11Token($owner))
        ->patchJson("/api/v1/management/payment-settings/gateways/{$gatewayId}/toggle")
        ->assertOk();
    expect((bool) DB::table('business_payment_method')->where('id', $gatewayId)->value('is_active'))->toBeFalse();

    // Test — success and failure. A sequence, not two Http::fake() calls:
    // the second fake appends a stub and the first one still wins.
    Http::fakeSequence('*')
        ->push(['status' => true, 'message' => 'Banks retrieved'])
        ->push(['status' => false, 'message' => 'Invalid key'], 401);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/gateways/{$gatewayId}/test")
        ->assertOk();

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/gateways/{$gatewayId}/test")
        ->assertStatus(422);

    // Remove.
    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/gateways/{$gatewayId}")
        ->assertOk();

    expect(DB::table('business_payment_method')->where('id', $gatewayId)->exists())->toBeFalse();
});

test('removing a gateway only clears its own business store assignments', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $paystack = PaymentMethod::where('code', 'paystack')->firstOrFail();

    $myGateway = ws11ConnectPaystack($owner);
    $theirGateway = ws11ConnectPaystack($otherOwner);

    $myStore = ws11Store($owner);
    $theirStore = ws11Store($otherOwner);

    DB::table('store_payment_method')->insert([
        ['store_id' => $myStore->id, 'payment_method_id' => $paystack->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['store_id' => $theirStore->id, 'payment_method_id' => $paystack->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/gateways/{$myGateway}")
        ->assertOk();

    expect(DB::table('store_payment_method')->where('store_id', $myStore->id)->exists())->toBeFalse();

    // Legacy deleted these rows platform-wide; the other tenant keeps theirs.
    expect(DB::table('store_payment_method')->where('store_id', $theirStore->id)->exists())->toBeTrue();
    expect(DB::table('business_payment_method')->where('id', $theirGateway)->exists())->toBeTrue();
});

test('a gateway is assigned to a store and shows up in the method info lists', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);
    $otherStore = ws11Store($owner, ['name' => 'Second Store']);

    $gatewayId = ws11ConnectPaystack($owner);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-methods/gateway/{$gatewayId}/assign", ['store_id' => $store->id])
        ->assertOk();

    $paystack = PaymentMethod::where('code', 'paystack')->firstOrFail();

    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $paystack->id)
        ->where('is_active', true)
        ->exists())->toBeTrue();

    $this->withToken(ws11Token($owner))
        ->getJson("/api/v1/management/payment-methods/gateway/{$gatewayId}/info")
        ->assertOk()
        ->assertJsonPath('data.method.code', 'paystack')
        ->assertJsonPath('data.assigned_stores.0.id', $store->id)
        ->assertJsonPath('data.available_stores.0.id', $otherStore->id);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-methods/gateway/{$gatewayId}/unassign/{$store->store_id}")
        ->assertOk();

    expect(DB::table('store_payment_method')->where('store_id', $store->id)->exists())->toBeFalse();
});

test('a bank is assigned to a store through the store-side endpoints', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    $bank = ws11Bank($owner, ['is_primary' => true]);
    $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/stores/{$store->store_id}/assign-bank", ['store_bank_id' => $bank->id])
        ->assertOk();

    expect(DB::table('store_bank')->where('store_id', $store->id)->where('store_bank_id', $bank->id)->exists())->toBeTrue();
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeTrue();

    // The four lists the modal renders.
    $this->withToken(ws11Token($owner))
        ->getJson("/api/v1/management/stores/{$store->store_id}/payment-methods")
        ->assertOk()
        ->assertJsonPath('data.assigned_banks.0.id', $bank->id)
        ->assertJsonCount(0, 'data.available_banks')
        ->assertJsonPath('data.bank_transfer_assigned', true);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/stores/{$store->store_id}/remove-bank/{$bank->id}")
        ->assertOk();

    expect(DB::table('store_bank')->where('store_id', $store->id)->exists())->toBeFalse();
    // bank_transfer leaves the store once no account remains.
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeFalse();
});

test('another business store or bank cannot be reached through the store endpoints', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirStore = ws11Store($otherOwner);
    $theirBank = ws11Bank($otherOwner);

    $token = ws11Token($owner);

    $this->withToken($token)
        ->getJson("/api/v1/management/stores/{$theirStore->store_id}/payment-methods")
        ->assertStatus(403);

    $this->withToken($token)
        ->postJson("/api/v1/management/stores/{$theirStore->store_id}/assign-bank", ['store_bank_id' => $theirBank->id])
        ->assertStatus(403);

    // Even pointed at their own store, the foreign bank is not found.
    $myStore = ws11Store($owner);

    $this->withToken($token)
        ->postJson("/api/v1/management/stores/{$myStore->store_id}/assign-bank", ['store_bank_id' => $theirBank->id])
        ->assertStatus(404);

    // Assignment cannot be aimed at another business's store either.
    $gatewayId = ws11ConnectPaystack($owner);

    $this->withToken($token)
        ->postJson("/api/v1/management/payment-methods/gateway/{$gatewayId}/assign", ['store_id' => $theirStore->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('store_id');
});

test('a bank method can be assigned to a store through the method endpoints', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    $bank = ws11Bank($owner, ['is_primary' => true]);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-methods/bank/{$bank->id}/assign", ['store_id' => $store->id])
        ->assertOk();

    expect(DB::table('store_bank')->where('store_id', $store->id)->where('store_bank_id', $bank->id)->exists())->toBeTrue();

    $this->withToken(ws11Token($owner))
        ->getJson("/api/v1/management/payment-methods/bank/{$bank->id}/info")
        ->assertOk()
        ->assertJsonPath('data.method.type', 'bank')
        ->assertJsonPath('data.method.id', $bank->id)
        ->assertJsonPath('data.assigned_stores.0.id', $store->id);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-methods/bank/{$bank->id}/unassign/{$store->store_id}")
        ->assertOk();

    expect(DB::table('store_bank')->where('store_id', $store->id)->exists())->toBeFalse();
});

test('the store payment mode is guarded by the configured methods', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    // Manual without a bank account is refused.
    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/stores/{$store->store_id}/toggle-mode", ['payment_mode' => 'manual'])
        ->assertStatus(422);

    ws11Bank($owner, ['is_primary' => true]);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/stores/{$store->store_id}/toggle-mode", ['payment_mode' => 'manual'])
        ->assertOk()
        ->assertJsonPath('data.store.payment_mode', 'manual');

    // Auto without an assigned, active Paystack gateway is refused.
    $gatewayId = ws11ConnectPaystack($owner);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/stores/{$store->store_id}/toggle-mode", ['payment_mode' => 'auto'])
        ->assertStatus(422);

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-methods/gateway/{$gatewayId}/assign", ['store_id' => $store->id])
        ->assertOk();

    $this->withToken(ws11Token($owner))
        ->postJson("/api/v1/management/payment-settings/stores/{$store->store_id}/toggle-mode", ['payment_mode' => 'auto'])
        ->assertOk()
        ->assertJsonPath('data.store.payment_mode', 'auto');
});

test('an unknown payment method type is rejected', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws11Token($owner))
        ->postJson('/api/v1/management/payment-methods/paystack/1/assign', ['store_id' => 1])
        ->assertStatus(422);
});

test('removing one of several assigned banks keeps bank transfer on the store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    $first = ws11Bank($owner, ['account_number' => '0111111111', 'is_primary' => true]);
    $second = ws11Bank($owner, ['account_number' => '0222222222']);

    $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    foreach ([$first, $second] as $bank) {
        DB::table('store_bank')->insert([
            'store_id' => $store->id,
            'store_bank_id' => $bank->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('store_payment_method')->insert([
        'store_id' => $store->id,
        'payment_method_id' => $bankTransfer->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-methods/bank/{$second->id}/unassign/{$store->store_id}")
        ->assertOk();

    // One account remains, so the store still takes transfers. Legacy stripped
    // the method unconditionally, turning a store's transfers off early.
    expect(DB::table('store_bank')->where('store_id', $store->id)->count())->toBe(1);
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeTrue();

    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-methods/bank/{$first->id}/unassign/{$store->store_id}")
        ->assertOk();

    // The last account went, so bank_transfer goes with it.
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeFalse();
});

test('deleting a business bank account clears bank transfer from stores left with no account', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws11Store($owner);

    // A primary payout account the store does not accept, so deleting the
    // two assigned accounts is allowed and the business stays bank transfer
    // enabled.
    ws11Bank($owner, ['account_number' => '0333333333', 'is_primary' => true]);
    $first = ws11Bank($owner, ['account_number' => '0111111111']);
    $second = ws11Bank($owner, ['account_number' => '0222222222']);

    $bankTransfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    foreach ([$first, $second] as $bank) {
        DB::table('store_bank')->insert([
            'store_id' => $store->id,
            'store_bank_id' => $bank->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('store_payment_method')->insert([
        'store_id' => $store->id,
        'payment_method_id' => $bankTransfer->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The store still has one assigned account after this deletion.
    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/bank-accounts/{$first->id}")
        ->assertOk();

    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeTrue();

    // The last assigned account goes; the store must stop offering transfers.
    $this->withToken(ws11Token($owner))
        ->deleteJson("/api/v1/management/payment-settings/bank-accounts/{$second->id}")
        ->assertOk();

    expect(DB::table('store_bank')->where('store_id', $store->id)->count())->toBe(0);
    expect(DB::table('store_payment_method')
        ->where('store_id', $store->id)
        ->where('payment_method_id', $bankTransfer->id)
        ->exists())->toBeFalse();

    // The business still has a payout account, so the business method stays on.
    $pivot = DB::table('business_payment_method')
        ->where('business_id', $owner->business_id)
        ->where('payment_method_id', $bankTransfer->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->is_active)->toBeTrue();
});
