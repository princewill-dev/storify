<?php

use App\Http\Controllers\Api\V1\Admin\CompanyServiceController;
use App\Models\ActivityLog;
use App\Models\CompanyService;
use App\Models\Testimonial;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * WS-18 — marketing content (admin console): testimonials & company services.
 *
 * Covers both CRUDs, the validation failures, the reorder and toggle flows,
 * the photo-convention fix (real uploads + the base64 backfill), cache
 * invalidation, the live `GET /api/v1/home` consumers and the platform-admin
 * boundary.
 *
 * The 1×1 PNG below stands in for a legacy row's base64 `data:` photo.
 */
const AD18_LEGACY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function ad18Token(User $user): string
{
    return $user->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad18SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad18AdminWithRole(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($roleName);

    return $user;
}

function ad18Headers(User $user): array
{
    // The admin SPA speaks JSON, so carry Accept explicitly: the multipart
    // uploads below cannot use postJson(), and without it Laravel answers a
    // validation failure with a redirect-back (302) instead of the API's
    // documented 422 {message, errors}.
    return [
        'Authorization' => 'Bearer '.ad18Token($user),
        'Accept' => 'application/json',
    ];
}

/**
 * Sanctum's request guard caches the user it resolved for the lifetime of the
 * test process, so without a reset the second and later requests in one test
 * would still be authenticated as the first caller. Reset it before every
 * request that introduces a new token, so each caller is judged on its own
 * credentials.
 */
function ad18FreshGuards(): void
{
    app('auth')->forgetGuards();
}

function ad18Testimonial(array $attributes = []): Testimonial
{
    static $sequence = 0;
    $sequence++;

    return Testimonial::create(array_merge([
        'name' => 'Ada '.$sequence,
        'photo' => 'testimonials/ada-'.$sequence.'.jpg',
        'occupation' => 'Founder',
        'message' => 'Great platform',
        'status' => 'active',
        'position' => 0,
    ], $attributes));
}

function ad18Service(array $attributes = []): CompanyService
{
    static $sequence = 0;
    $sequence++;

    return CompanyService::create(array_merge([
        'order' => $sequence,
        'title' => 'Service '.$sequence,
        'description' => 'We help you set it up',
        'status' => 'active',
    ], $attributes));
}

test('the testimonial list paginates, orders by position and reports counts', function () {
    $admin = ad18SuperAdmin();

    ad18Testimonial(['name' => 'First', 'position' => 0, 'status' => 'active']);
    ad18Testimonial(['name' => 'Second', 'position' => 1, 'status' => 'inactive']);
    ad18Testimonial(['name' => 'Third', 'position' => 5, 'status' => 'active']);

    $response = $this->getJson('/api/v1/admin/testimonials', ad18Headers($admin))->assertOk();

    expect(array_column($response->json('data.testimonials'), 'name'))->toBe(['First', 'Second', 'Third'])
        ->and($response->json('data.public_cap'))->toBe(6)
        ->and($response->json('data.counts.total'))->toBe(3)
        ->and($response->json('data.counts.active'))->toBe(2)
        ->and($response->json('data.counts.inactive'))->toBe(1)
        ->and($response->json('meta.total'))->toBe(3);
});

test('the testimonial list supports search, status filter, sorting and rejects unknown sort columns', function () {
    $admin = ad18SuperAdmin();

    ad18Testimonial(['name' => 'Ada Lovelace', 'occupation' => 'Mathematician']);
    ad18Testimonial(['name' => 'Grace Hopper', 'occupation' => 'Admiral', 'status' => 'inactive']);

    $search = $this->getJson('/api/v1/admin/testimonials?q=grace', ad18Headers($admin))->assertOk();
    expect(array_column($search->json('data.testimonials'), 'name'))->toBe(['Grace Hopper']);

    $filtered = $this->getJson('/api/v1/admin/testimonials?status=inactive', ad18Headers($admin))->assertOk();
    expect(array_column($filtered->json('data.testimonials'), 'name'))->toBe(['Grace Hopper']);

    $sorted = $this->getJson('/api/v1/admin/testimonials?sort=name&direction=desc', ad18Headers($admin))->assertOk();
    expect(array_column($sorted->json('data.testimonials'), 'name'))->toBe(['Grace Hopper', 'Ada Lovelace']);

    // Never pass a request-supplied column to orderBy.
    $this->getJson('/api/v1/admin/testimonials?sort=photo', ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    $this->getJson('/api/v1/admin/testimonials?per_page=500', ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('a testimonial is created as a real storage upload, never base64', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    $response = $this->post('/api/v1/admin/testimonials', [
        'name' => 'Chidi Okeke',
        'occupation' => 'CEO',
        'message' => 'Storify changed how we sell.',
        'photo' => UploadedFile::fake()->image('chidi.jpg', 120, 120),
        'status' => 'active',
        'position' => 2,
    ], ad18Headers($admin))->assertCreated();

    $testimonial = Testimonial::query()->firstOrFail();

    expect($testimonial->photo)->toStartWith('testimonials/')
        ->and($testimonial->photo)->not->toStartWith('data:')
        ->and(Storage::disk('public')->exists($testimonial->photo))->toBeTrue()
        ->and($response->json('data.testimonial.photo_url'))->toEndWith('/storage/'.$testimonial->photo)
        ->and($response->json('data.testimonial.is_legacy_photo'))->toBeFalse()
        ->and($response->json('data.testimonial.position'))->toBe(2);

    expect(ActivityLog::where('action', 'testimonial_created')->exists())->toBeTrue();
});

test('testimonial creation validates the legacy rules', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    $this->post('/api/v1/admin/testimonials', [
        'name' => '',
        'occupation' => '',
        'message' => '',
        'status' => 'sideways',
        'position' => -1,
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'occupation', 'message', 'photo', 'status', 'position']);

    // The photo must be an image — a PDF is refused.
    $this->post('/api/v1/admin/testimonials', [
        'name' => 'Doc Uploader',
        'occupation' => 'Tester',
        'message' => 'Nope',
        'photo' => UploadedFile::fake()->create('terms.pdf', 40, 'application/pdf'),
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');

    expect(Testimonial::count())->toBe(0);
});

test('stored testimonial text is plain text so injected markup cannot execute', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    $this->post('/api/v1/admin/testimonials', [
        'name' => '<script>alert(1)</script>Ada',
        'occupation' => 'Founder',
        'message' => 'Hello <img src=x onerror=alert(2)> world',
        'photo' => UploadedFile::fake()->image('ada.png'),
        'status' => 'active',
    ], ad18Headers($admin))->assertCreated();

    $testimonial = Testimonial::query()->firstOrFail();

    expect($testimonial->name)->toBe('Ada')
        ->and($testimonial->message)->toBe('Hello  world');

    // A field that is nothing but markup is refused, not stored empty.
    $this->post('/api/v1/admin/testimonials', [
        'name' => '<b></b>',
        'occupation' => 'Founder',
        'message' => 'Hi',
        'photo' => UploadedFile::fake()->image('ada2.png'),
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('editing a testimonial keeps the current photo unless a new one is uploaded', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    Storage::disk('public')->put('testimonials/old.jpg', 'old-bytes');

    $testimonial = ad18Testimonial(['photo' => 'testimonials/old.jpg']);

    // No file: the path is untouched.
    $this->put('/api/v1/admin/testimonials/'.$testimonial->id, [
        'name' => 'Ada Updated',
        'occupation' => 'Founder',
        'message' => 'Now with an edit.',
        'status' => 'inactive',
        'position' => 4,
    ], ad18Headers($admin))->assertOk();

    $testimonial->refresh();
    expect($testimonial->photo)->toBe('testimonials/old.jpg')
        ->and($testimonial->name)->toBe('Ada Updated')
        ->and($testimonial->status)->toBe('inactive')
        ->and($testimonial->position)->toBe(4);

    // With a file: the old file is replaced on disk.
    $this->put('/api/v1/admin/testimonials/'.$testimonial->id, [
        'name' => 'Ada Updated',
        'occupation' => 'Founder',
        'message' => 'Now with an edit.',
        'status' => 'active',
        'photo' => UploadedFile::fake()->image('new.png'),
    ], ad18Headers($admin))->assertOk();

    $testimonial->refresh();

    expect($testimonial->photo)->not->toBe('testimonials/old.jpg')
        ->and(Storage::disk('public')->exists($testimonial->photo))->toBeTrue()
        ->and(Storage::disk('public')->exists('testimonials/old.jpg'))->toBeFalse();

    expect(ActivityLog::where('action', 'testimonial_updated')->exists())->toBeTrue();
});

test('an update accepts method spoofing so the multipart SPA edit works', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    $testimonial = ad18Testimonial(['name' => 'Before']);

    $this->post('/api/v1/admin/testimonials/'.$testimonial->id, [
        '_method' => 'PUT',
        'name' => 'After',
        'occupation' => 'Founder',
        'message' => 'Spoofed update',
        'status' => 'active',
        'photo' => UploadedFile::fake()->image('after.png'),
    ], ad18Headers($admin))->assertOk();

    expect($testimonial->fresh()->name)->toBe('After');
});

test('deleting a testimonial removes the row and its stored photo', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    Storage::disk('public')->put('testimonials/gone.jpg', 'bytes');

    $testimonial = ad18Testimonial(['photo' => 'testimonials/gone.jpg']);

    $this->deleteJson('/api/v1/admin/testimonials/'.$testimonial->id, [], ad18Headers($admin))
        ->assertOk();

    expect(Testimonial::whereKey($testimonial->id)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('testimonials/gone.jpg'))->toBeFalse()
        ->and(ActivityLog::where('action', 'testimonial_deleted')->exists())->toBeTrue();
});

test('a testimonial can be toggled active and inactive from the list', function () {
    $admin = ad18SuperAdmin();
    $testimonial = ad18Testimonial(['status' => 'active']);

    $this->postJson('/api/v1/admin/testimonials/'.$testimonial->id.'/toggle', [], ad18Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.testimonial.status', 'inactive');

    $this->postJson('/api/v1/admin/testimonials/'.$testimonial->id.'/toggle', [], ad18Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.testimonial.status', 'active');
});

test('legacy base64 testimonial photos render on the admin list and are backfilled to storage files', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    // Distinct positions keep the list order deterministic for the assertion.
    $legacy = ad18Testimonial(['photo' => 'data:image/png;base64,'.AD18_LEGACY_PNG, 'position' => 1]);
    $junk = ad18Testimonial(['photo' => 'data:image/png;base64,not valid base64 !!!', 'position' => 2]);
    ad18Testimonial(['photo' => 'testimonials/modern.jpg', 'position' => 3]);

    // The office list keeps rendering legacy rows instead of a broken image.
    $response = $this->getJson('/api/v1/admin/testimonials', ad18Headers($admin))->assertOk();
    $legacyRow = collect($response->json('data.testimonials'))->firstWhere('id', $legacy->id);

    expect($legacyRow['is_legacy_photo'])->toBeTrue()
        ->and($legacyRow['photo_url'])->toStartWith('data:image/png;base64,')
        ->and($response->json('data.counts.legacy_photos'))->toBe(2);

    // Backfill: the decodable row becomes a real file, the junk row is
    // skipped (never deleted) and the response reports what is left.
    $this->postJson('/api/v1/admin/testimonials/backfill-photos', [], ad18Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.converted', 1)
        ->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.remaining', 1);

    $legacy->refresh();

    expect($legacy->photo)->toStartWith('testimonials/legacy-')
        ->and(Storage::disk('public')->exists($legacy->photo))->toBeTrue()
        ->and(Storage::disk('public')->get($legacy->photo))->toBe(base64_decode(AD18_LEGACY_PNG))
        ->and($junk->fresh()->photo)->toStartWith('data:');

    // The home API now builds a storage URL for it — the live broken-image
    // bug this backfill exists to fix.
    $this->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath(
            'data.testimonials.0.photo_url',
            asset('storage/'.$legacy->fresh()->photo),
        );
});

test('the home api serves at most six active testimonials in position order', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    foreach (range(1, 7) as $index) {
        $this->post('/api/v1/admin/testimonials', [
            'name' => 'Person '.$index,
            'occupation' => 'Tester',
            'message' => 'Testimonial '.$index,
            'photo' => UploadedFile::fake()->image('p'.$index.'.png'),
            'status' => 'active',
            'position' => $index,
        ], ad18Headers($admin))->assertCreated();
    }

    // Hidden rows must never reach the site.
    ad18Testimonial(['name' => 'Hidden', 'status' => 'inactive', 'position' => 1]);

    $home = $this->getJson('/api/v1/home')->assertOk();

    expect($home->json('data.testimonials'))->toHaveCount(6)
        ->and(array_column($home->json('data.testimonials'), 'name'))->toBe([
            'Person 1', 'Person 2', 'Person 3', 'Person 4', 'Person 5', 'Person 6',
        ]);
});

test('the company services list orders by display order and reports counts', function () {
    $admin = ad18SuperAdmin();

    ad18Service(['title' => 'Second', 'order' => 2]);
    ad18Service(['title' => 'First', 'order' => 1, 'status' => 'inactive']);

    $response = $this->getJson('/api/v1/admin/company-services', ad18Headers($admin))->assertOk();

    expect(array_column($response->json('data.services'), 'title'))->toBe(['First', 'Second'])
        ->and($response->json('data.counts.total'))->toBe(2)
        ->and($response->json('data.counts.active'))->toBe(1)
        ->and($response->json('data.counts.inactive'))->toBe(1);
});

test('a company service is created with a normalised page link, an image and a busted nav cache', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    Cache::put(CompanyServiceController::NAV_CACHE_KEY, collect(['stale']), 300);

    $response = $this->post('/api/v1/admin/company-services', [
        'order' => 3,
        'title' => 'Store Setup',
        'description' => 'We set your store up.',
        'page_link' => '/services/store-setup',
        'background_image' => UploadedFile::fake()->image('setup.jpg', 400, 200),
        'status' => 'active',
    ], ad18Headers($admin))->assertCreated();

    $service = CompanyService::query()->firstOrFail();

    expect($service->page_link)->toBe('services/store-setup')
        ->and($service->background_image_path)->toStartWith('company_services/')
        ->and(Storage::disk('public')->exists($service->background_image_path))->toBeTrue()
        ->and($response->json('data.service.image_url'))->toEndWith('/storage/'.$service->background_image_path)
        ->and($response->json('data.service.page_url'))->toEndWith('/services/store-setup')
        // The public nav must not keep serving a stale menu.
        ->and(Cache::get(CompanyServiceController::NAV_CACHE_KEY))->toBeNull();

    expect(ActivityLog::where('action', 'company_service_created')->exists())->toBeTrue();
});

test('company service validation enforces uniqueness, schemes and image rules', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    ad18Service(['page_link' => 'about']);

    // A leading slash is stripped BEFORE the uniqueness check — legacy
    // validated the raw value and only then normalised it, so "/about" slipped
    // past as a "different" link and blew up on the unique index.
    $this->postJson('/api/v1/admin/company-services', [
        'title' => 'Duplicate',
        'page_link' => '/about',
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('page_link');

    $this->postJson('/api/v1/admin/company-services', [
        'title' => 'Scheme',
        'page_link' => 'javascript:alert(1)',
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('page_link');

    $this->postJson('/api/v1/admin/company-services', [
        'title' => 'Traversal',
        'page_link' => 'services/../../etc/passwd',
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('page_link');

    $this->post('/api/v1/admin/company-services', [
        'title' => 'Bad image',
        'background_image' => UploadedFile::fake()->create('payload.svg', 20, 'image/svg+xml'),
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('background_image');

    $this->postJson('/api/v1/admin/company-services', [
        'title' => '',
        'status' => 'sideways',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'status']);

    expect(CompanyService::count())->toBe(1);
});

test('editing a company service keeps the page link unique to itself and replaces the image', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    Storage::disk('public')->put('company_services/old.jpg', 'old-bytes');

    $service = ad18Service(['page_link' => 'about', 'background_image_path' => 'company_services/old.jpg']);
    $other = ad18Service(['page_link' => 'pricing']);

    // Saving its own link back is not a uniqueness violation.
    $this->put('/api/v1/admin/company-services/'.$service->id, [
        'order' => 1,
        'title' => 'About us',
        'description' => null,
        'page_link' => 'about',
        'status' => 'inactive',
    ], ad18Headers($admin))->assertOk();

    expect($service->fresh()->title)->toBe('About us')
        ->and($service->fresh()->status)->toBe('inactive')
        ->and($service->fresh()->background_image_path)->toBe('company_services/old.jpg');

    // Another service's link is refused.
    $this->putJson('/api/v1/admin/company-services/'.$service->id, [
        'order' => 1,
        'title' => 'About us',
        'page_link' => 'pricing',
        'status' => 'active',
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('page_link');

    // A new image replaces and deletes the old one.
    $this->put('/api/v1/admin/company-services/'.$service->id, [
        'order' => 1,
        'title' => 'About us',
        'page_link' => 'about',
        'background_image' => UploadedFile::fake()->image('new.png'),
        'status' => 'active',
    ], ad18Headers($admin))->assertOk();

    $service->refresh();

    expect($service->background_image_path)->not->toBe('company_services/old.jpg')
        ->and(Storage::disk('public')->exists($service->background_image_path))->toBeTrue()
        ->and(Storage::disk('public')->exists('company_services/old.jpg'))->toBeFalse()
        ->and($other->fresh()->page_link)->toBe('pricing');
});

test('company services can be reordered, toggled and deleted, busting the nav cache each time', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();
    Storage::disk('public')->put('company_services/byebye.jpg', 'bytes');

    $first = ad18Service(['title' => 'First', 'order' => 1]);
    $second = ad18Service(['title' => 'Second', 'order' => 2]);
    $third = ad18Service(['title' => 'Third', 'order' => 3, 'background_image_path' => 'company_services/byebye.jpg']);

    Cache::put(CompanyServiceController::NAV_CACHE_KEY, collect(['stale']), 300);
    $this->postJson('/api/v1/admin/company-services/reorder', [
        'items' => [
            ['id' => $third->id, 'order' => 1],
            ['id' => $first->id, 'order' => 2],
            ['id' => $second->id, 'order' => 3],
        ],
    ], ad18Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.updated', 3)
        ->assertJsonPath('message', 'Service order updated.');

    expect($third->fresh()->order)->toBe(1)
        ->and($first->fresh()->order)->toBe(2)
        ->and(Cache::get(CompanyServiceController::NAV_CACHE_KEY))->toBeNull();

    Cache::put(CompanyServiceController::NAV_CACHE_KEY, collect(['stale']), 300);
    $this->postJson('/api/v1/admin/company-services/'.$first->id.'/toggle', [], ad18Headers($admin))
        ->assertOk()
        ->assertJsonPath('data.service.status', 'inactive');

    expect($first->fresh()->status)->toBe('inactive')
        ->and(Cache::get(CompanyServiceController::NAV_CACHE_KEY))->toBeNull();

    Cache::put(CompanyServiceController::NAV_CACHE_KEY, collect(['stale']), 300);
    $this->deleteJson('/api/v1/admin/company-services/'.$third->id, [], ad18Headers($admin))->assertOk();

    expect(CompanyService::whereKey($third->id)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('company_services/byebye.jpg'))->toBeFalse()
        ->and(Cache::get(CompanyServiceController::NAV_CACHE_KEY))->toBeNull();
});

test('reorder validation failures are 422s, not legacy 500s', function () {
    $admin = ad18SuperAdmin();
    $service = ad18Service(['order' => 4]);

    $this->postJson('/api/v1/admin/company-services/reorder', ['items' => []], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');

    $this->postJson('/api/v1/admin/company-services/reorder', [
        'items' => [['id' => $service->id, 'order' => 'not-a-number']],
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.order');

    // Duplicate ids cannot silently write one row twice.
    $this->postJson('/api/v1/admin/company-services/reorder', [
        'items' => [
            ['id' => $service->id, 'order' => 1],
            ['id' => $service->id, 'order' => 2],
        ],
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.1.id');

    // A missing service is reported before anything is written.
    $this->postJson('/api/v1/admin/company-services/reorder', [
        'items' => [['id' => 999999, 'order' => 1]],
    ], ad18Headers($admin))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Some services no longer exist. Refresh the list and try again.');

    expect($service->fresh()->order)->toBe(4);
});

test('company service mutations are reflected immediately in the home services list', function () {
    Storage::fake('public');

    $admin = ad18SuperAdmin();

    $this->post('/api/v1/admin/company-services', [
        'order' => 2,
        'title' => 'Store Setup',
        'description' => 'We set it up',
        'status' => 'active',
    ], ad18Headers($admin))->assertCreated();

    $this->post('/api/v1/admin/company-services', [
        'order' => 1,
        'title' => 'Integrations',
        'description' => 'We connect things',
        'status' => 'active',
    ], ad18Headers($admin))->assertCreated();

    $this->post('/api/v1/admin/company-services', [
        'order' => 0,
        'title' => 'Hidden Service',
        'status' => 'inactive',
    ], ad18Headers($admin))->assertCreated();

    $home = $this->getJson('/api/v1/home')->assertOk();

    expect(array_column($home->json('data.services'), 'title'))->toBe(['Integrations', 'Store Setup']);
});

test('only platform accounts can reach the marketing content screens', function () {
    // A business-scoped owner: its in-business "Super Admin" role carries the
    // full permission bundle, admin.* names included (admin.content among
    // them), so the permission gate alone would let it publish platform-wide
    // marketing content — the controller's platform guard is what stops a
    // leaked admin-audience token.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);
    expect($owner->can('admin.content'))->toBeTrue();

    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials', [
        'Authorization' => 'Bearer '.$owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // A management token cannot cross audiences.
    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials', [
        'Authorization' => 'Bearer '.$owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken,
    ])->assertStatus(403);

    // An admin account without the permission is refused by the route gate.
    $permissionless = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials', ad18Headers($permissionless))->assertStatus(403);
    ad18FreshGuards();
    $this->getJson('/api/v1/admin/company-services', ad18Headers($permissionless))->assertStatus(403);

    // A Platform Admin carries admin.content and is allowed.
    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials', ad18Headers(ad18AdminWithRole('Platform Admin')))->assertOk();
    ad18FreshGuards();
    $this->getJson('/api/v1/admin/company-services', ad18Headers(ad18AdminWithRole('Platform Admin')))->assertOk();

    // Support Admin also carries admin.content (they curate storefront copy).
    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials', ad18Headers(ad18AdminWithRole('Support Admin')))->assertOk();

    ad18FreshGuards();
    $this->getJson('/api/v1/admin/testimonials')->assertStatus(401);
});
