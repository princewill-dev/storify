<?php

use App\Mail\SettingsUpdated;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Admin WS2 — platform settings & branding.
 *
 * Helpers are `ad02`-prefixed because Pest loads every test file into one
 * process and AdminApiTest already owns `adminToken` / `superAdminUser`.
 */
function ad02Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad02Headers(User $admin): array
{
    return [
        'Authorization' => 'Bearer '.ad02Token($admin),
        'Accept' => 'application/json',
    ];
}

function ad02SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

/** A platform admin holding `$roleName` and nothing else. */
function ad02PlatformAdmin(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId(null);

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    $admin->assignRole($roleName);

    return $admin;
}

/**
 * Every field the legacy form posted, at its default value. The stored row and
 * the posted payload share this baseline so a test override is the *only*
 * difference between them.
 */
function ad02Baseline(): array
{
    return [
        'company_name' => 'Storify',
        'company_description' => 'Multi-store commerce',
        'support_email' => 'help@storify.test',
        'support_phone' => '+2348012345678',
        'company_address' => '1 Marina, Lagos',
        'branch_address' => '2 Broad St, Lagos',
        'main_store_id' => null,
        'store_creation_limit' => 5,
        'trial_enabled' => true,
        'trial_days' => 7,
        'greeting_modal_enabled' => false,
        'greeting_modal_frequency' => 'never',
        'og_title' => null,
        'og_description' => null,
        'og_url' => null,
        'og_type' => 'website',
    ];
}

function ad02Settings(array $attributes = []): Setting
{
    return Setting::create(array_merge(ad02Baseline(), $attributes));
}

function ad02FullPayload(array $overrides = []): array
{
    return array_merge(ad02Baseline(), $overrides);
}

function ad02Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Home Store',
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

test('platform settings expose the full payload with store and currency options', function () {
    $admin = ad02SuperAdmin();
    [$owner] = createBusinessOwner();

    ad02Store($owner, ['name' => 'Alpha Store']);
    ad02Store($owner, ['name' => 'Gone Store', 'status' => Store::STATUS_DELETED]);

    Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
    Currency::create(['name' => 'Dollar', 'code' => 'USD', 'symbol' => '$']);

    $response = $this->getJson('/api/v1/admin/settings', ad02Headers($admin))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'settings' => [
                    'company_name', 'company_description', 'company_logo_url', 'company_favicon_url',
                    'company_certificate_url', 'company_certificate_is_pdf', 'support_email',
                    'support_phone', 'company_address', 'branch_address', 'main_store_id', 'main_store',
                    'store_creation_limit', 'trial_enabled', 'trial_days', 'default_currency_id',
                    'greeting_modal_enabled', 'greeting_modal_frequency', 'og_title', 'og_description',
                    'og_image_url', 'og_url', 'og_type', 'updated_at',
                ],
                'stores',
                'currencies',
                'options' => ['og_types', 'greeting_modal_frequencies'],
            ],
        ]);

    // A fresh install still reports the effective legacy defaults.
    expect($response->json('data.settings.store_creation_limit'))->toBe(5)
        ->and($response->json('data.settings.trial_enabled'))->toBeTrue()
        ->and($response->json('data.settings.trial_days'))->toBe(7)
        ->and($response->json('data.settings.og_type'))->toBe('website')
        ->and($response->json('data.settings.greeting_modal_frequency'))->toBe('never')
        ->and($response->json('data.settings.default_currency_id'))
        ->toBe((int) Currency::query()->where('code', 'NGN')->value('id'));

    // The homepage picker only lists non-deleted stores.
    expect(array_column($response->json('data.stores'), 'name'))->toBe(['Alpha Store'])
        ->and($response->json('data.currencies'))->toHaveCount(2)
        ->and($response->json('data.options.og_types'))->toBe(['website', 'article', 'product'])
        ->and(array_column($response->json('data.options.greeting_modal_frequencies'), 'value'))
        ->toContain('once_per_session');
});

test('saving settings persists every legacy field and creates the singleton row', function () {
    $admin = ad02SuperAdmin();
    [$owner] = createBusinessOwner();

    $store = ad02Store($owner);
    Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
    $dollar = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'symbol' => '$']);

    expect(Setting::query()->count())->toBe(0);

    $response = $this->putJson('/api/v1/admin/settings', ad02FullPayload([
        'company_name' => 'Storify Platform',
        'company_description' => 'Sell anywhere in Nigeria',
        'support_email' => 'support@storify.test',
        'support_phone' => '+2348099999999',
        'company_address' => '3 Admiralty Way, Lekki',
        'branch_address' => '9 Ikorodu Road, Lagos',
        'main_store_id' => $store->id,
        'store_creation_limit' => 12,
        'trial_enabled' => false,
        'trial_days' => 21,
        'greeting_modal_enabled' => true,
        'greeting_modal_frequency' => 'once_per_week',
        'og_title' => 'Storify — commerce for everyone',
        'og_description' => 'Launch a storefront in minutes',
        'og_url' => 'https://storify.test/home',
        'og_type' => 'article',
        'default_currency_id' => $dollar->id,
    ]), ad02Headers($admin))->assertOk();

    $settings = Setting::query()->first();

    expect($settings->company_name)->toBe('Storify Platform')
        ->and($settings->company_description)->toBe('Sell anywhere in Nigeria')
        ->and($settings->support_email)->toBe('support@storify.test')
        ->and($settings->support_phone)->toBe('+2348099999999')
        ->and($settings->company_address)->toBe('3 Admiralty Way, Lekki')
        ->and($settings->branch_address)->toBe('9 Ikorodu Road, Lagos')
        ->and($settings->main_store_id)->toBe($store->id)
        ->and($settings->store_creation_limit)->toBe(12)
        ->and($settings->trial_enabled)->toBeFalse()
        ->and($settings->trial_days)->toBe(21)
        ->and($settings->greeting_modal_enabled)->toBeTrue()
        ->and($settings->greeting_modal_frequency)->toBe('once_per_week')
        ->and($settings->og_title)->toBe('Storify — commerce for everyone')
        ->and($settings->og_type)->toBe('article');

    // The change report carries key names, and the payload round-trips.
    expect($response->json('data.changed_keys'))
        ->toContain('company_name')
        ->toContain('main_store_id')
        ->toContain('default_currency_id')
        ->and($response->json('data.settings.main_store.name'))->toBe('Home Store')
        ->and($response->json('data.settings.trial_enabled'))->toBeFalse();
});

test('saving settings busts every branding and main-store cache', function () {
    $admin = ad02SuperAdmin();
    [$owner] = createBusinessOwner();
    $store = ad02Store($owner);

    ad02Settings();

    $keys = ['company_settings', 'home_api_company', 'admin_main_store', 'home_main_store', 'search_suggested_products'];

    foreach ($keys as $key) {
        Cache::put($key, 'stale', 600);
    }

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['main_store_id' => $store->id]), ad02Headers($admin))
        ->assertOk();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }
});

test('the default currency is exclusive after saving', function () {
    $admin = ad02SuperAdmin();
    ad02Settings();

    $naira = Currency::create(['name' => 'Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true]);
    $cedi = Currency::create(['name' => 'Cedi', 'code' => 'GHS', 'symbol' => '₵']);

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['default_currency_id' => $cedi->id]), ad02Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.settings.default_currency_id', $cedi->id);

    expect((int) $naira->fresh()->is_default)->toBe(0)
        ->and((int) $cedi->fresh()->is_default)->toBe(1)
        ->and(Currency::query()->where('is_default', true)->count())->toBe(1);
});

test('og type and greeting frequency are enum validated', function () {
    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload([
        'og_type' => 'tracking-pixel',
        'greeting_modal_frequency' => 'hourly',
    ]), ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['og_type', 'greeting_modal_frequency']);

    $settings = Setting::query()->first();

    expect($settings->og_type)->toBe('website')
        ->and($settings->greeting_modal_frequency)->toBe('never');
});

test('store creation limit and trial days are bounds checked', function () {
    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload([
        'store_creation_limit' => 0,
        'trial_days' => 91,
    ]), ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['store_creation_limit', 'trial_days']);

    expect(Setting::query()->first()->store_creation_limit)->toBe(5)
        ->and(Setting::query()->first()->trial_days)->toBe(7);
});

test('the homepage store must be an existing non-deleted store', function () {
    $admin = ad02SuperAdmin();
    [$owner] = createBusinessOwner();
    $deleted = ad02Store($owner, ['name' => 'Deleted Store', 'status' => Store::STATUS_DELETED]);
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['main_store_id' => 999999]), ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('main_store_id');

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['main_store_id' => $deleted->id]), ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('main_store_id');

    expect(Setting::query()->first()->main_store_id)->toBeNull();

    // A homepage store deleted after it was saved stays visible, so the admin
    // sees what is stored instead of a blank select.
    Setting::query()->first()->update(['main_store_id' => $deleted->id]);

    $response = $this->getJson('/api/v1/admin/settings', ad02Headers($admin))->assertOk();

    expect(array_column($response->json('data.stores'), 'name'))->toContain('Deleted Store')
        ->and($response->json('data.settings.main_store.name'))->toBe('Deleted Store');
});

test('a logo upload is stored, exposed as a url and replaces the previous file', function () {
    Storage::fake('public');

    $admin = ad02SuperAdmin();
    ad02Settings(['company_logo_path' => 'company/old-logo.png']);
    Storage::disk('public')->put('company/old-logo.png', 'old-bytes');

    $response = $this->put('/api/v1/admin/settings', [
        'company_logo' => UploadedFile::fake()->image('logo.png', 120, 60),
    ], ad02Headers($admin))->assertOk();

    $path = Setting::query()->first()->company_logo_path;

    expect($path)->not->toBe('company/old-logo.png')
        ->and($path)->toStartWith('company/')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists('company/old-logo.png'))->toBeFalse()
        ->and($response->json('data.settings.company_logo_url'))->toEndWith('/storage/'.$path);
});

test('a non-image logo is refused with a per-field error', function () {
    Storage::fake('public');

    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->put('/api/v1/admin/settings', [
        'company_logo' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
    ], ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('company_logo');

    expect(Setting::query()->first()->company_logo_path)->toBeNull();
});

test('an oversized certificate is refused', function () {
    Storage::fake('public');

    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->put('/api/v1/admin/settings', [
        'company_certificate' => UploadedFile::fake()->create('certificate.pdf', 6000, 'application/pdf'),
    ], ad02Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('company_certificate');
});

test('an ico favicon is accepted, the way legacy intended but never achieved', function () {
    Storage::fake('public');

    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->put('/api/v1/admin/settings', [
        'company_favicon' => UploadedFile::fake()->create('favicon.ico', 32, 'image/x-icon'),
    ], ad02Headers($admin))->assertOk();

    expect(Setting::query()->first()->company_favicon_path)->toStartWith('company/');
});

test('the legacy api keys vault is not writable through the settings api', function () {
    $admin = ad02SuperAdmin();
    ad02Settings(['api_keys' => ['legacy' => 'keep-me']]);

    $this->putJson('/api/v1/admin/settings', ad02FullPayload([
        'company_name' => 'Renamed',
        'api_keys' => ['injected' => 'nope'],
    ]), ad02Headers($admin))->assertOk();

    expect(Setting::query()->first()->api_keys)->toBe(['legacy' => 'keep-me']);
});

test('the multipart save works through method spoofing, the way the admin spa sends it', function () {
    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->post('/api/v1/admin/settings', [
        '_method' => 'PUT',
        'company_name' => 'Spoofed Save',
        'trial_enabled' => '0',
        'trial_days' => '14',
    ], ad02Headers($admin))->assertOk();

    $settings = Setting::query()->first();

    expect($settings->company_name)->toBe('Spoofed Save')
        ->and($settings->trial_enabled)->toBeFalse()
        ->and($settings->trial_days)->toBe(14);
});

test('a real change queues the settings updated mail to the first superadmin', function () {
    Mail::fake();

    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['company_name' => 'Renamed']), ad02Headers($admin))
        ->assertOk();

    Mail::assertQueued(SettingsUpdated::class, function (SettingsUpdated $mail) use ($admin) {
        return $mail->hasTo($admin->email) && $mail->changes === ['company_name' => true];
    });
});

test('a save that changes nothing queues no mail and reports no changed keys', function () {
    Mail::fake();

    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(), ad02Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.changed_keys', []);

    Mail::assertNothingQueued();
});

test('saving settings writes an audit row with changed key names only', function () {
    $admin = ad02SuperAdmin();
    ad02Settings();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload([
        'company_name' => 'After Secret Name',
        'support_email' => 'secret@storify.test',
    ]), ad02Headers($admin))->assertOk();

    $log = ActivityLog::query()->where('action', 'settings_updated')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata['changed_keys'])->toBe(['company_name', 'support_email'])
        ->and(json_encode($log->metadata))->not->toContain('After Secret Name')
        ->and(json_encode($log->metadata))->not->toContain('secret@storify.test');
});

test('platform settings reject management tokens and admins without the permission', function () {
    [$owner] = createBusinessOwner();
    $support = ad02PlatformAdmin('Support Admin');

    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/settings', [
        'Authorization' => 'Bearer '.$managementToken,
        'Accept' => 'application/json',
    ])->assertStatus(403);

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(), [
        'Authorization' => 'Bearer '.$managementToken,
        'Accept' => 'application/json',
    ])->assertStatus(403);

    $this->getJson('/api/v1/admin/settings', ad02Headers($support))->assertStatus(403);
    $this->putJson('/api/v1/admin/settings', ad02FullPayload(), ad02Headers($support))->assertStatus(403);
});

test('platform settings refuse a business-scoped admin token even when it carries the permission', function () {
    // A business's in-business "Super Admin" role bundles every admin.* name
    // (including admin.settings), so the permission gate alone would let a
    // leaked admin-audience token from a business account rewrite the platform
    // branding, homepage store and trial rules. The controller's platform-role
    // guard is what stops it.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.settings'))->toBeTrue();

    $this->getJson('/api/v1/admin/settings', ad02Headers($owner))->assertStatus(403);
    $this->putJson('/api/v1/admin/settings', ad02FullPayload(), ad02Headers($owner))->assertStatus(403);

    expect(Setting::query()->count())->toBe(0);
});

test('a platform admin holding admin.settings can read and save', function () {
    $admin = ad02PlatformAdmin('Platform Admin');
    ad02Settings();

    $this->getJson('/api/v1/admin/settings', ad02Headers($admin))->assertOk();

    $this->putJson('/api/v1/admin/settings', ad02FullPayload(['company_name' => 'Renamed']), ad02Headers($admin))
        ->assertOk();

    expect(Setting::query()->first()->company_name)->toBe('Renamed');
});
