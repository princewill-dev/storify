<?php

use App\Models\Store;
use App\Models\StorefrontPage;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\FeatureGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

function ws58Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws58Store(User $owner, array $attributes = []): Store
{
    static $sequence = 0;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.++$sequence,
        'slug' => 'ws58-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $attributes));
}

function ws58Plan(bool $storefront = false): SubscriptionPlan
{
    return SubscriptionPlan::create([
        'name' => 'WS58 '.($storefront ? 'With' : 'Without').' Storefront',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'tier' => 1,
        'sort_order' => 1,
        'entitlements' => [
            EntitlementRegistry::STORES => 3,
            EntitlementRegistry::STOREFRONT => $storefront,
        ],
    ]);
}

function ws58Subscribe(User $user, SubscriptionPlan $plan): Subscription
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

function ws58Url(Store $store, string $path = ''): string
{
    return '/api/v1/management/stores/'.$store->store_id.'/storefront-settings'.$path;
}

function ws58PageUrl(Store $store, ?int $pageId = null): string
{
    $base = '/api/v1/management/stores/'.$store->store_id.'/storefront-pages';

    return $pageId === null ? $base : $base.'/'.$pageId;
}

/* ------------------------------------------------------------------- reads */

test('the storefront settings workspace returns fallbacks and owned pages', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner, ['name' => 'Acme Store', 'description' => 'Handmade goods for the home.']);

    StorefrontPage::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'title' => 'Shipping',
        'slug' => 'shipping',
        'is_published' => true,
    ]);

    $response = $this->withToken(ws58Token($owner))->getJson(ws58Url($store));

    $response->assertOk()
        ->assertJsonPath('data.site.site_title', null)
        ->assertJsonPath('data.site.effective.title', 'Acme Store')
        ->assertJsonPath('data.site.effective.description', 'Handmade goods for the home.')
        ->assertJsonPath('data.site.effective.url', 'https://'.$store->slug.'.'.config('frontend.storefront_main_domain'))
        ->assertJsonPath('data.pages.0.slug', 'shipping')
        ->assertJsonPath('data.pages.0.path', '/pages/shipping')
        ->assertJsonMissingPath('data.pages.0.content');
});

test('another business cannot read or write a storefront settings workspace', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$other] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = ws58Store($owner);

    $this->withToken(ws58Token($other))
        ->getJson(ws58Url($store))
        ->assertForbidden();

    $this->withToken(ws58Token($other))
        ->putJson(ws58Url($store), ['site_title' => 'Hijacked'])
        ->assertForbidden();
});

test('a role without stores settings cannot open the workspace', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $staff = User::factory()->create([
        'business_id' => $owner->business_id,
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
    ]);

    setPermissionsTeamId($owner->business_id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws58Token($staff))
        ->getJson(ws58Url($store))
        ->assertForbidden();

    $this->withToken(ws58Token($staff))
        ->postJson(ws58PageUrl($store), ['title' => 'About', 'slug' => 'about'])
        ->assertForbidden();
});

/* ---------------------------------------------------------------- settings */

test('site metadata saves and preserves fields the request did not carry', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), [
            'site_title' => 'Acme Online',
            'meta_description' => 'Shop the whole range.',
        ])
        ->assertOk()
        ->assertJsonPath('data.site.site_title', 'Acme Online')
        ->assertJsonPath('data.site.effective.title', 'Acme Online');

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), ['meta_description' => 'Updated description.'])
        ->assertOk()
        ->assertJsonPath('data.site.site_title', 'Acme Online')
        ->assertJsonPath('data.site.meta_description', 'Updated description.');
});

test('site metadata validation caps the title and description', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), ['site_title' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_title');

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), ['meta_description' => str_repeat('b', 321)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('meta_description');
});

test('the og image can be uploaded, replaced and removed', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->post(ws58Url($store), [
            '_method' => 'PUT',
            'og_image' => UploadedFile::fake()->image('first.jpg', 1200, 630),
        ])
        ->assertOk();

    $settings = $store->fresh()->storefrontSettings;
    $first = $settings->og_image_path;

    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists($first);

    $this->withToken(ws58Token($owner))
        ->post(ws58Url($store), [
            '_method' => 'PUT',
            'og_image' => UploadedFile::fake()->image('second.png', 1200, 630),
        ])
        ->assertOk();

    $settings = $store->fresh()->storefrontSettings;

    expect($settings->og_image_path)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($settings->og_image_path);

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), ['remove_og_image' => true])
        ->assertOk()
        ->assertJsonPath('data.site.og_image_url', null);

    expect($store->fresh()->storefrontSettings->og_image_path)->toBeNull();
    Storage::disk('public')->assertMissing($settings->og_image_path);
});

test('an og image must be an image of an accepted type and size', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withHeaders(['Accept' => 'application/json'])
        ->withToken(ws58Token($owner))
        ->post(ws58Url($store), [
            '_method' => 'PUT',
            'og_image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('og_image');
});

/* ------------------------------------------------------------------- pages */

test('a page is created with a normalised slug and sanitized content', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $response = $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), [
            'title' => 'About Us',
            'slug' => 'About Us',
            'content' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>',
            'is_published' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'About Us')
        ->assertJsonPath('data.slug', 'about-us')
        ->assertJsonPath('data.path', '/pages/about-us')
        ->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.show_in_footer', true);

    $page = StorefrontPage::sole();

    expect($page->content)->toContain('<strong>world</strong>')
        ->and($page->content)->not->toContain('<script')
        ->and($page->content)->not->toContain('alert(1)');
});

test('the three friendly slugs get root paths', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    foreach (['about', 'terms', 'privacy'] as $slug) {
        $this->withToken(ws58Token($owner))
            ->postJson(ws58PageUrl($store), ['title' => ucfirst($slug), 'slug' => $slug])
            ->assertCreated()
            ->assertJsonPath('data.path', '/'.$slug);
    }
});

test('a slug is unique inside its store only', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $other = ws58Store($owner);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), ['title' => 'About', 'slug' => 'about'])
        ->assertCreated();

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), ['title' => 'About again', 'slug' => 'about'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($other), ['title' => 'About', 'slug' => 'about'])
        ->assertCreated();
});

test('a slug may be changed, and keeping its own value is not a collision', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $page = StorefrontPage::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'title' => 'Shipping',
        'slug' => 'shipping',
    ]);

    $this->withToken(ws58Token($owner))
        ->putJson(ws58PageUrl($store, $page->id), ['slug' => 'shipping'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'shipping');

    $this->withToken(ws58Token($owner))
        ->putJson(ws58PageUrl($store, $page->id), ['slug' => 'Delivery'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'delivery')
        ->assertJsonPath('data.path', '/pages/delivery');
});

test('the list toggles update one boolean without resending the page', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $page = StorefrontPage::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'title' => 'Terms',
        'slug' => 'terms',
        'content' => '<p>Terms</p>',
        'is_published' => false,
    ]);

    $this->withToken(ws58Token($owner))
        ->putJson(ws58PageUrl($store, $page->id), ['is_published' => true])
        ->assertOk()
        ->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.content', '<p>Terms</p>');
});

test('a page can be loaded, deleted, and cannot be reached from another store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $other = ws58Store($owner);
    $store = ws58Store($owner);

    $page = StorefrontPage::create([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'title' => 'Privacy',
        'slug' => 'privacy',
        'content' => '<p>Private</p>',
    ]);

    $this->withToken(ws58Token($owner))
        ->getJson(ws58PageUrl($other, $page->id))
        ->assertNotFound();

    $this->withToken(ws58Token($owner))
        ->putJson(ws58PageUrl($other, $page->id), ['title' => 'Stolen'])
        ->assertNotFound();

    $this->withToken(ws58Token($owner))
        ->deleteJson(ws58PageUrl($other, $page->id))
        ->assertNotFound();

    $this->withToken(ws58Token($owner))
        ->getJson(ws58PageUrl($store, $page->id))
        ->assertOk()
        ->assertJsonPath('data.content', '<p>Private</p>');

    $this->withToken(ws58Token($owner))
        ->deleteJson(ws58PageUrl($store, $page->id))
        ->assertOk();

    expect(StorefrontPage::find($page->id))->toBeNull();
});

test('a storefront is limited to fifty pages', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    for ($i = 1; $i <= 50; $i++) {
        StorefrontPage::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'title' => 'Page '.$i,
            'slug' => 'page-'.$i,
        ]);
    }

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), ['title' => 'One more', 'slug' => 'one-more'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
});

/* ------------------------------------------------------------- sanitization */

test('page html is sanitized against scripts, embeds and unsafe urls', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), [
            'title' => 'Safety',
            'slug' => 'safety',
            'content' => implode('', [
                '<p onclick="alert(1)">Safe</p>',
                '<script>alert(2)</script>',
                '<iframe src="https://evil.test"></iframe>',
                '<img src="x" onerror="alert(3)">',
                '<a href="javascript:alert(4)">bad</a>',
                '<a href="https://example.com" target="_blank">good</a>',
            ]),
        ])
        ->assertCreated();

    $content = (string) StorefrontPage::sole()->content;

    expect($content)->not->toContain('<script')
        ->and($content)->not->toContain('<iframe')
        ->and($content)->not->toContain('<img')
        ->and($content)->not->toContain('onclick')
        ->and($content)->not->toContain('onerror')
        ->and($content)->not->toContain('javascript:')
        ->and($content)->toContain('Safe')
        ->and($content)->toContain('noopener')
        ->and($content)->toContain('noreferrer');
});

test('page content is capped and an empty body is stored as null', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws58Store($owner);

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), [
            'title' => 'Too long',
            'slug' => 'too-long',
            'content' => str_repeat('a', 200001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), [
            'title' => 'Empty',
            'slug' => 'empty',
            'content' => '',
        ])
        ->assertCreated()
        ->assertJsonPath('data.content', null);
});

/* ---------------------------------------------------------------- plan gate */

test('writing storefront settings requires the storefront entitlement', function () {
    [$owner] = createBusinessOwner();
    ws58Subscribe($owner, ws58Plan(storefront: false));

    $store = ws58Store($owner);

    // The read is deliberately ungated: a downgraded business can still see
    // what its shop is serving.
    $this->withToken(ws58Token($owner))
        ->getJson(ws58Url($store))
        ->assertOk();

    $this->withToken(ws58Token($owner))
        ->putJson(ws58Url($store), ['site_title' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE);

    $this->withToken(ws58Token($owner))
        ->postJson(ws58PageUrl($store), ['title' => 'About', 'slug' => 'about'])
        ->assertForbidden()
        ->assertJsonPath('code', FeatureGate::REFUSAL_CODE);
});

test('the write routes carry the storefront feature gate', function () {
    $middleware = fn (string $name): array => collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->getName() === $name)
        ->gatherMiddleware();

    expect($middleware('api.management.storefront-settings.show'))->not->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront-settings.update'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront-pages.store'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront-pages.update'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront-pages.destroy'))->toContain('plan.feature:storefront')
        ->and($middleware('api.management.storefront-pages.show'))->not->toContain('plan.feature:storefront');
});
