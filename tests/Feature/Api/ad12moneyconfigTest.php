<?php

use App\Models\BankAccount;
use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Vat;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| WS-12 — Money configuration (admin console)
|--------------------------------------------------------------------------
| Covers the VAT rate CRUD with its single-active invariant (create, update,
| guarded delete and the 0%-superseding Disable VAT action), the payment
| method list/toggle that gates storefront checkout, the bank account CRUD
| with logo upload/replacement/deletion, the coupon code-edit parity gap, and
| the audience / platform-role / permission refusals.
*/

function ad12Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad12SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad12FinanceAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole('Finance Admin');

    return $user;
}

function ad12AdminToken(User $admin): array
{
    return ['Authorization' => 'Bearer '.ad12Token($admin)];
}

// ---------------------------------------------------------------------------
// VAT rates
// ---------------------------------------------------------------------------

test('the VAT list returns the active rate first with pagination meta', function () {
    $admin = ad12FinanceAdmin();

    Vat::create(['percentage' => 7.5, 'active' => false, 'effective_at' => now()->subMonth()]);
    $current = Vat::create(['percentage' => 5, 'active' => true, 'effective_at' => now()]);

    $response = $this->getJson('/api/v1/admin/vats', ad12AdminToken($admin));

    $response->assertOk()
        ->assertJsonPath('data.vats.0.id', $current->id)
        ->assertJsonPath('data.vats.0.percentage', 5.0)
        ->assertJsonPath('data.vats.0.active', true)
        ->assertJsonStructure([
            'data' => ['vats' => [['id', 'percentage', 'active', 'effective_at', 'created_at']]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(2);
});

test('creating a VAT rate supersedes the previous active rate', function () {
    $admin = ad12FinanceAdmin();

    $previous = Vat::create(['percentage' => 5, 'active' => true, 'effective_at' => now()->subMonth()]);

    $response = $this->postJson('/api/v1/admin/vats', ['percentage' => 7.5], ad12AdminToken($admin));

    $response->assertCreated()
        ->assertJsonPath('data.vat.percentage', 7.5)
        ->assertJsonPath('data.vat.active', true);

    // Only one rate may be active; the new record always wins and
    // effective_at defaults to now.
    expect(Vat::where('active', true)->count())->toBe(1)
        ->and($previous->fresh()->active)->toBeFalse();

    $created = Vat::orderByDesc('id')->first();
    expect($created->effective_at)->not->toBeNull();
});

test('creating a zero rate is allowed and becomes the active rate', function () {
    $admin = ad12FinanceAdmin();

    $active = Vat::create(['percentage' => 7.5, 'active' => true, 'effective_at' => now()]);

    $this->postJson('/api/v1/admin/vats', ['percentage' => 0], ad12AdminToken($admin))
        ->assertCreated()
        ->assertJsonPath('data.vat.percentage', 0.0)
        ->assertJsonPath('data.vat.active', true);

    expect($active->fresh()->active)->toBeFalse();
});

test('a VAT rate requires a percentage between 0 and 100', function () {
    $admin = ad12FinanceAdmin();

    $this->postJson('/api/v1/admin/vats', [], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('percentage');

    $this->postJson('/api/v1/admin/vats', ['percentage' => 150], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('percentage');

    $this->postJson('/api/v1/admin/vats', ['percentage' => 5, 'effective_at' => 'not-a-date'], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('effective_at');
});

test('marking a VAT rate active deactivates the previous one', function () {
    $admin = ad12FinanceAdmin();

    $first = Vat::create(['percentage' => 5, 'active' => true, 'effective_at' => now()->subMonth()]);
    $second = Vat::create(['percentage' => 7.5, 'active' => false, 'effective_at' => now()]);

    $this->putJson("/api/v1/admin/vats/{$second->id}", [
        'percentage' => 7.5,
        'active' => true,
    ], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.vat.active', true);

    expect($second->fresh()->active)->toBeTrue()
        ->and($first->fresh()->active)->toBeFalse()
        ->and(Vat::where('active', true)->count())->toBe(1);
});

test('editing an inactive VAT rate leaves the active one alone', function () {
    $admin = ad12FinanceAdmin();

    $active = Vat::create(['percentage' => 5, 'active' => true, 'effective_at' => now()]);
    $old = Vat::create(['percentage' => 2.5, 'active' => false, 'effective_at' => now()->subYear()]);

    $this->putJson("/api/v1/admin/vats/{$old->id}", ['percentage' => 3], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.vat.percentage', 3.0)
        ->assertJsonPath('data.vat.active', false);

    expect($active->fresh()->active)->toBeTrue()
        ->and((float) $active->fresh()->percentage)->toBe(5.0);
});

test('the active VAT rate cannot be switched off directly', function () {
    $admin = ad12FinanceAdmin();

    $active = Vat::create(['percentage' => 7.5, 'active' => true, 'effective_at' => now()]);

    $this->putJson("/api/v1/admin/vats/{$active->id}", [
        'percentage' => 7.5,
        'active' => false,
    ], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonPath('message', 'VAT cannot be switched off directly. Use the Disable VAT action to create a 0% rate, or activate another rate first.');

    expect($active->fresh()->active)->toBeTrue();
});

test('an inactive VAT rate can be deleted but the active one cannot', function () {
    $admin = ad12FinanceAdmin();

    $active = Vat::create(['percentage' => 7.5, 'active' => true, 'effective_at' => now()]);
    $old = Vat::create(['percentage' => 2.5, 'active' => false, 'effective_at' => now()->subYear()]);

    $this->deleteJson("/api/v1/admin/vats/{$old->id}", [], ad12AdminToken($admin))->assertOk();

    expect(Vat::find($old->id))->toBeNull();

    $this->deleteJson("/api/v1/admin/vats/{$active->id}", [], ad12AdminToken($admin))
        ->assertStatus(422);

    expect(Vat::find($active->id))->not->toBeNull();
});

test('the disable action creates a 0% rate superseding every other rate', function () {
    $admin = ad12FinanceAdmin();

    $active = Vat::create(['percentage' => 7.5, 'active' => true, 'effective_at' => now()]);
    $historic = Vat::create(['percentage' => 2.5, 'active' => false, 'effective_at' => now()->subYear()]);

    $response = $this->postJson("/api/v1/admin/vats/{$active->id}/toggle", [], ad12AdminToken($admin));

    $response->assertOk()
        ->assertJsonPath('data.vat.percentage', 0.0)
        ->assertJsonPath('data.vat.active', true)
        ->assertJsonPath('message', '0% VAT created.');

    // Legacy defect fixed: the old controller created the 0% row but left the
    // previous rate active too. The invariant is enforced on this path now.
    expect($active->fresh()->active)->toBeFalse()
        ->and($historic->fresh()->active)->toBeFalse()
        ->and(Vat::where('active', true)->count())->toBe(1)
        ->and(Vat::count())->toBe(3);
});

test('VAT pricing reads the newest active rate', function () {
    $admin = ad12FinanceAdmin();

    $this->postJson('/api/v1/admin/vats', ['percentage' => 7.5], ad12AdminToken($admin))->assertCreated();

    $this->postJson('/api/v1/admin/vats', ['percentage' => 10], ad12AdminToken($admin))->assertCreated();

    // The storefront / POS readers order by effective_at + id within `active`.
    $rate = Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first();

    expect((float) $rate->percentage)->toBe(10.0);
});

// ---------------------------------------------------------------------------
// Payment methods
// ---------------------------------------------------------------------------

test('the payment method list is ordered by name and exposes the toggle state', function () {
    $admin = ad12FinanceAdmin();

    $response = $this->getJson('/api/v1/admin/payment-methods', ad12AdminToken($admin));

    $response->assertOk()
        ->assertJsonStructure(['data' => ['payment_methods' => [['id', 'name', 'code', 'type', 'description', 'is_active']]]]);

    $names = array_column($response->json('data.payment_methods'), 'name');

    expect($names)->toBe(collect($names)->sort()->values()->all())
        ->and(collect($response->json('data.payment_methods'))->pluck('code'))
        ->toContain('paystack', 'bank_transfer');
});

test('toggling a payment method flips what checkout offers', function () {
    $admin = ad12FinanceAdmin();

    $bank = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

    $this->postJson("/api/v1/admin/payment-methods/{$bank->id}/toggle", [], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.payment_method.is_active', false)
        ->assertJsonPath('message', 'Bank Transfer disabled successfully.');

    // Storefront checkout reads PaymentMethod::active().
    expect(PaymentMethod::active()->pluck('code'))->not->toContain('bank_transfer');

    $this->postJson("/api/v1/admin/payment-methods/{$bank->id}/toggle", [], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.payment_method.is_active', true)
        ->assertJsonPath('message', 'Bank Transfer enabled successfully.');

    expect(PaymentMethod::active()->pluck('code'))->toContain('bank_transfer');
});

test('a missing payment method is a 404', function () {
    $admin = ad12FinanceAdmin();

    $this->postJson('/api/v1/admin/payment-methods/999999/toggle', [], ad12AdminToken($admin))
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Bank accounts
// ---------------------------------------------------------------------------

test('the bank account list is ordered by sort order and paginated', function () {
    $admin = ad12FinanceAdmin();

    BankAccount::create(['bank_name' => 'Zenith', 'account_number' => '2000000001', 'sort_order' => 2]);
    BankAccount::create(['bank_name' => 'GTBank', 'account_number' => '1000000001', 'account_name' => 'ACME Ltd', 'sort_order' => 1]);

    $response = $this->getJson('/api/v1/admin/bank-accounts', ad12AdminToken($admin));

    $response->assertOk()
        ->assertJsonPath('data.bank_accounts.0.bank_name', 'GTBank')
        ->assertJsonPath('data.bank_accounts.0.account_name', 'ACME Ltd')
        ->assertJsonPath('data.bank_accounts.0.logo_url', null)
        ->assertJsonPath('data.bank_accounts.1.account_name', null)
        ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
});

test('bank accounts can be searched and filtered by status', function () {
    $admin = ad12FinanceAdmin();

    BankAccount::create(['bank_name' => 'GTBank', 'account_number' => '1000000001', 'is_active' => true, 'sort_order' => 1]);
    BankAccount::create(['bank_name' => 'Zenith', 'account_number' => '2000000002', 'is_active' => false, 'sort_order' => 2]);

    $this->getJson('/api/v1/admin/bank-accounts?q=zenith', ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonCount(1, 'data.bank_accounts')
        ->assertJsonPath('data.bank_accounts.0.bank_name', 'Zenith');

    $this->getJson('/api/v1/admin/bank-accounts?status=active', ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonCount(1, 'data.bank_accounts')
        ->assertJsonPath('data.bank_accounts.0.bank_name', 'GTBank');
});

test('a bank account can be created with a logo', function () {
    Storage::fake('public');

    $admin = ad12FinanceAdmin();

    $response = $this->post('/api/v1/admin/bank-accounts', [
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'account_name' => 'Zimoziswift Limited',
        'sort_order' => 3,
        'is_active' => '1',
        'logo' => UploadedFile::fake()->image('gtbank.png', 40, 40),
    ], ad12AdminToken($admin));

    $response->assertCreated()
        ->assertJsonPath('data.bank_account.bank_name', 'GTBank')
        ->assertJsonPath('data.bank_account.account_name', 'Zimoziswift Limited')
        ->assertJsonPath('data.bank_account.sort_order', 3)
        ->assertJsonPath('data.bank_account.is_active', true);

    $account = BankAccount::firstOrFail();

    expect($account->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($account->logo);
    expect($response->json('data.bank_account.logo_url'))->toContain($account->logo);
});

test('a bank account requires a bank name and account number', function () {
    $admin = ad12FinanceAdmin();

    $this->postJson('/api/v1/admin/bank-accounts', [], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bank_name', 'account_number']);
});

test('a bank account logo must be a small image', function () {
    Storage::fake('public');

    $admin = ad12FinanceAdmin();

    $this->post('/api/v1/admin/bank-accounts', [
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'logo' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('logo');

    expect(BankAccount::count())->toBe(0);
});

test('replacing a bank account logo deletes the old file', function () {
    Storage::fake('public');

    $admin = ad12FinanceAdmin();

    Storage::disk('public')->put('bank-logos/old.png', 'old-bytes');

    $account = BankAccount::create([
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'logo' => 'bank-logos/old.png',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->post("/api/v1/admin/bank-accounts/{$account->id}", [
        '_method' => 'PUT',
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'sort_order' => 1,
        'is_active' => '1',
        'logo' => UploadedFile::fake()->image('new.png', 40, 40),
    ], ad12AdminToken($admin))->assertOk();

    $account->refresh();

    Storage::disk('public')->assertMissing('bank-logos/old.png');
    Storage::disk('public')->assertExists($account->logo);
    expect($account->logo)->not->toBe('bank-logos/old.png');
});

test('editing a bank account without a new logo keeps the current one', function () {
    Storage::fake('public');

    $admin = ad12FinanceAdmin();

    Storage::disk('public')->put('bank-logos/keep.png', 'bytes');

    $account = BankAccount::create([
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'logo' => 'bank-logos/keep.png',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->putJson("/api/v1/admin/bank-accounts/{$account->id}", [
        'bank_name' => 'GTBank Plc',
        'account_number' => '0123456789',
        'is_active' => false,
    ], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.bank_account.bank_name', 'GTBank Plc')
        ->assertJsonPath('data.bank_account.is_active', false);

    $account->refresh();

    expect($account->logo)->toBe('bank-logos/keep.png')
        ->and($account->is_active)->toBeFalse();
    Storage::disk('public')->assertExists('bank-logos/keep.png');
});

test('a bank account can be toggled active or inactive', function () {
    $admin = ad12FinanceAdmin();

    $account = BankAccount::create([
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/toggle-active", [], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.bank_account.is_active', false);

    $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/toggle-active", [], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.bank_account.is_active', true);
});

test('deleting a bank account removes the row and its logo', function () {
    Storage::fake('public');

    $admin = ad12FinanceAdmin();

    Storage::disk('public')->put('bank-logos/gone.png', 'bytes');

    $account = BankAccount::create([
        'bank_name' => 'GTBank',
        'account_number' => '0123456789',
        'logo' => 'bank-logos/gone.png',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->deleteJson("/api/v1/admin/bank-accounts/{$account->id}", [], ad12AdminToken($admin))->assertOk();

    expect(BankAccount::find($account->id))->toBeNull();
    Storage::disk('public')->assertMissing('bank-logos/gone.png');
});

// ---------------------------------------------------------------------------
// Coupon code editing (OF-4.2 parity gap)
// ---------------------------------------------------------------------------

test('a coupon code can be edited, uppercased and stays unique ignoring itself', function () {
    $admin = ad12FinanceAdmin();

    $coupon = Coupon::create([
        'code' => 'SAVE10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", [
        'code' => 'save20',
        'name' => 'Renamed',
    ], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'SAVE20')
        ->assertJsonPath('data.coupon.name', 'Renamed');

    // Re-submitting the same code on itself is not a uniqueness violation.
    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['code' => 'SAVE20'], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'SAVE20');

    expect(Coupon::where('code', 'SAVE20')->count())->toBe(1);
});

test('a coupon code taken by another coupon is refused', function () {
    $admin = ad12FinanceAdmin();

    Coupon::create(['code' => 'TAKEN', 'discount_type' => 'fixed', 'discount_value' => 500, 'is_active' => true]);
    $coupon = Coupon::create(['code' => 'MINE', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['code' => 'taken'], ad12AdminToken($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect($coupon->fresh()->code)->toBe('MINE');
});

test('a coupon update without a code leaves the code alone', function () {
    $admin = ad12FinanceAdmin();

    $coupon = Coupon::create(['code' => 'KEEP', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['discount_value' => 15], ad12AdminToken($admin))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'KEEP')
        ->assertJsonPath('data.coupon.discount_value', 15.0);
});

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

test('money configuration refuses non-platform, unpermitted and guest callers', function () {
    // A business-scoped "Super Admin" carries the admin.* permission names,
    // so an admin-audience token from a business account must still fail the
    // platform-role guard on every money-configuration route.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.finance'))->toBeTrue();

    $ownerToken = ['Authorization' => 'Bearer '.ad12Token($owner)];

    $this->getJson('/api/v1/admin/vats', $ownerToken)->assertStatus(403);
    $this->getJson('/api/v1/admin/payment-methods', $ownerToken)->assertStatus(403);
    $this->getJson('/api/v1/admin/bank-accounts', $ownerToken)->assertStatus(403);
    $this->postJson('/api/v1/admin/vats', ['percentage' => 5], $ownerToken)->assertStatus(403);
    $this->postJson('/api/v1/admin/payment-methods/'.PaymentMethod::firstOrFail()->id.'/toggle', [], $ownerToken)->assertStatus(403);
    $this->postJson('/api/v1/admin/bank-accounts', ['bank_name' => 'X', 'account_number' => '1'], $ownerToken)->assertStatus(403);

    // A management-audience token cannot reach the admin API at all.
    $managementToken = ['Authorization' => 'Bearer '.$owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken];

    $this->getJson('/api/v1/admin/vats', $managementToken)->assertStatus(403);
    $this->getJson('/api/v1/admin/bank-accounts', $managementToken)->assertStatus(403);

    // An admin account without the permission is stopped by the route gate.
    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $plainToken = ad12AdminToken($plainAdmin);

    $this->getJson('/api/v1/admin/vats', $plainToken)->assertStatus(403);
    $this->getJson('/api/v1/admin/payment-methods', $plainToken)->assertStatus(403);
    $this->getJson('/api/v1/admin/bank-accounts', $plainToken)->assertStatus(403);

    // Guests are unauthenticated.
    $this->getJson('/api/v1/admin/vats')->assertStatus(401);
    $this->getJson('/api/v1/admin/bank-accounts')->assertStatus(401);

    // The seeded finance admin and a superadmin pass.
    $financeAdmin = ad12FinanceAdmin();

    $this->getJson('/api/v1/admin/vats', ad12AdminToken($financeAdmin))->assertOk();
    $this->getJson('/api/v1/admin/payment-methods', ad12AdminToken($financeAdmin))->assertOk();
    $this->getJson('/api/v1/admin/bank-accounts', ad12AdminToken($financeAdmin))->assertOk();
    $this->getJson('/api/v1/admin/vats', ad12AdminToken(ad12SuperAdmin()))->assertOk();
});

test('coupon code editing refuses non-platform and unpermitted callers', function () {
    $coupon = Coupon::create(['code' => 'GUARDED', 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);

    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    $ownerToken = ['Authorization' => 'Bearer '.ad12Token($owner)];

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['code' => 'HIJACK'], $ownerToken)->assertStatus(403);

    expect($coupon->fresh()->code)->toBe('GUARDED');

    $plainAdmin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['code' => 'HIJACK'], ad12AdminToken($plainAdmin))->assertStatus(403);

    expect($coupon->fresh()->code)->toBe('GUARDED');

    $admin = ad12FinanceAdmin();

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", ['code' => 'EDITED'], ad12AdminToken($admin))
        ->assertOk();
});
