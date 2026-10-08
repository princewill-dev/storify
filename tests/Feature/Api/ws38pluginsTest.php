<?php

use App\Models\BusinessPlugin;
use App\Models\Store;
use App\Models\StorePlugin;
use App\Models\User;
use App\Support\Plugins\PluginRegistry;
use App\Support\Plugins\PluginTagRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
| WS-38 — Plugins (third-party marketing and analytics integrations).
|
| The behaviour that matters here is not the CRUD; it is that a value a
| business types ends up inside a <script> on a live storefront. So these
| tests cover the resolution order (business default vs store override), the
| tenant boundary, and — most importantly — that a value which fails its
| allow-list pattern is refused at the door AND not emitted from storage if it
| somehow got in.
*/

function ws38Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws38Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Store '.fake()->unique()->numerify('####'),
        'slug' => 'ws38-'.fake()->unique()->numerify('######'),
        'status' => Store::STATUS_ACTIVE,
        'payment_mode' => 'manual',
    ], $attributes));
}

/** A syntactically valid Meta Pixel id — 15 digits. */
function ws38PixelId(): string
{
    return (string) fake()->numerify('###############');
}

beforeEach(function () {
    // Deterministic storefront host, since the verifier builds its URL from
    // this rather than deriving it (deriving it is a known recurring bug).
    config(['frontend.storefront_main_domain' => 'storify.buzz']);
});

test('the catalogue lists every plugin with its fields and no tag templates', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws38Token($owner))
        ->getJson('/api/v1/management/plugins')
        ->assertOk();

    $catalogue = $response->json('data.catalogue');
    $keys = array_column($catalogue, 'key');

    expect($keys)->toContain('google_ads', 'meta_pixel', 'ga4', 'gtm', 'google_search_console', 'whatsapp');

    $metaPixel = collect($catalogue)->firstWhere('key', 'meta_pixel');
    expect($metaPixel['fields'][0]['key'])->toBe('pixel_id');

    // The payload must not ship the snippets we render — the browser has no
    // business having them.
    expect($response->json('data.catalogue.0'))->not->toHaveKey('head');
});

test('connecting a plugin stores the values and serves them on the public tracking endpoint', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);
    $pixel = ws38PixelId();

    $this->withToken(ws38Token($owner))
        ->putJson('/api/v1/management/plugins/meta_pixel', [
            'is_enabled' => true,
            'config' => ['pixel_id' => $pixel],
        ])
        ->assertOk();

    expect(BusinessPlugin::where('plugin_key', 'meta_pixel')->first()?->config)
        ->toBe(['pixel_id' => $pixel]);

    $response = $this->getJson("/api/v1/storefront/{$store->slug}/tracking")->assertOk();

    $inline = implode("\n", array_column($response->json('data.tags.inline'), 'js'));
    expect($inline)->toContain("'{$pixel}'");
});

test('a store override wins over the business default', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    $businessPixel = '111111111111111';
    $storePixel = '222222222222222';

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => $businessPixel],
    ])->assertOk();

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'store_id' => $store->id,
        'config' => ['pixel_id' => $storePixel],
    ])->assertOk();

    $inline = implode("\n", array_column($this->getJson("/api/v1/storefront/{$store->slug}/tracking")->json('data.tags.inline'), 'js'));

    expect($inline)->toContain($storePixel)
        ->and($inline)->not->toContain($businessPixel);
});

test('deleting a store override falls back to the business default rather than to off', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    $businessPixel = '333333333333333';

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => $businessPixel],
    ])->assertOk();

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'store_id' => $store->id,
        'config' => ['pixel_id' => '444444444444444'],
    ])->assertOk();

    $this->withToken(ws38Token($owner))
        ->deleteJson("/api/v1/management/plugins/meta_pixel?store_id={$store->id}")
        ->assertOk();

    expect(StorePlugin::where('store_id', $store->id)->exists())->toBeFalse();

    $inline = implode("\n", array_column($this->getJson("/api/v1/storefront/{$store->slug}/tracking")->json('data.tags.inline'), 'js'));
    expect($inline)->toContain($businessPixel);
});

test('a store can switch off a plugin the business enabled', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => ws38PixelId()],
    ])->assertOk();

    // A disabled store row is an override, not an absence — the difference is
    // the whole reason precedence keys off the row's existence.
    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => false,
        'store_id' => $store->id,
        'config' => ['pixel_id' => ws38PixelId()],
    ])->assertOk();

    $tags = $this->getJson("/api/v1/storefront/{$store->slug}/tracking")->json('data.tags');

    expect($tags['inline'])->toBe([]);
});

test('a value that fails its allow-list pattern is refused and never stored', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws38Token($owner))
        ->putJson('/api/v1/management/plugins/meta_pixel', [
            'is_enabled' => true,
            'config' => ['pixel_id' => '123456789012345");alert(1);//'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('config.pixel_id');

    expect(BusinessPlugin::count())->toBe(0);
});

test('every template-interpolated field rejects a script-closing payload', function () {
    $attacks = [
        'meta_pixel' => ['pixel_id' => '123456789012345");alert(1);//'],
        'tiktok_pixel' => ['pixel_id' => 'ABC123</script><img src=x onerror=alert(1)>'],
        'ga4' => ['measurement_id' => 'G-ABC"><script>alert(1)</script>'],
        'google_ads' => ['conversion_id' => 'AW-1<script>'],
        'gtm' => ['container_id' => 'GTM-<script>'],
        'pinterest_tag' => ['tag_id' => '1234567890123<script>'],
        'linkedin_insight' => ['partner_id' => '123<script>'],
        'clarity' => ['project_id' => 'abcdefghij<script>'],
        'google_search_console' => ['token' => 'abc" ><script>alert(1)</script>'],
        'bing_webmaster' => ['token' => 'abc<script>'],
        'meta_domain' => ['token' => 'abc<script>'],
        'crisp' => ['website_id' => 'not-a-uuid<script>'],
    ];

    foreach ($attacks as $key => $config) {
        expect(fn () => PluginRegistry::validatedConfig($key, $config))
            ->toThrow(ValidationException::class, null, "expected {$key} to reject its payload");
    }

    // And nothing renders from a set that never validated.
    $rendered = PluginTagRenderer::render($attacks);

    expect($rendered['scripts'])->toBe([])
        ->and($rendered['inline'])->toBe([])
        ->and($rendered['metas'])->toBe([])
        ->and($rendered['widgets'])->toBe([])
        ->and($rendered['plugins'])->toBe([]);
});

test('stored config that no longer satisfies its rules is not emitted', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    // Simulates a row written before a rule was tightened.
    StorePlugin::create([
        'business_id' => $owner->business_id,
        'store_id' => $store->id,
        'plugin_key' => 'meta_pixel',
        'is_enabled' => true,
        'config' => ['pixel_id' => 'not-a-pixel-id'],
    ]);

    $tags = $this->getJson("/api/v1/storefront/{$store->slug}/tracking")->json('data.tags');

    expect($tags['inline'])->toBe([]);
});

test('the public payload exposes no config, ids or business internals', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);
    $pixel = ws38PixelId();

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => $pixel],
    ])->assertOk();

    $data = $this->getJson("/api/v1/storefront/{$store->slug}/tracking")->assertOk()->json('data');

    expect(array_keys($data))->toBe(['store', 'tags'])
        ->and($data['store'])->toBe($store->slug)
        ->and($data)->not->toHaveKey('business_id');
});

test('a store from another business cannot be configured or checked', function () {
    [$ownerA] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$ownerB] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $storeB = ws38Store($ownerB);

    $this->withToken(ws38Token($ownerA))
        ->putJson('/api/v1/management/plugins/meta_pixel', [
            'is_enabled' => true,
            'store_id' => $storeB->id,
            'config' => ['pixel_id' => ws38PixelId()],
        ])
        ->assertForbidden();

    expect(StorePlugin::where('store_id', $storeB->id)->exists())->toBeFalse();
});

test('an unknown plugin key is a 404 and never reaches a query', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $this->withToken(ws38Token($owner))
        ->putJson('/api/v1/management/plugins/not_a_plugin', [
            'is_enabled' => true,
            'config' => ['x' => 'y'],
        ])
        ->assertNotFound();
});

test('a business without the settings plugins permission is refused', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $owner->business_id,
    ]);
    setPermissionsTeamId($owner->business_id);
    $staff->assignRole('Store Associate');

    $this->withToken(ws38Token($staff))
        ->getJson('/api/v1/management/plugins')
        ->assertForbidden();
});

test('the Google tag loader is emitted once for GA4 and Google Ads together', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    foreach ([
        'ga4' => ['measurement_id' => 'G-ABCDE12345'],
        'google_ads' => ['conversion_id' => 'AW-123456789'],
    ] as $key => $config) {
        $this->withToken(ws38Token($owner))
            ->putJson("/api/v1/management/plugins/{$key}", ['is_enabled' => true, 'config' => $config])
            ->assertOk();
    }

    $tags = $this->getJson("/api/v1/storefront/{$store->slug}/tracking")->json('data.tags');

    $loaderUrls = array_column($tags['scripts'], 'src');
    $gtagLoaders = array_filter($loaderUrls, fn (string $url) => str_contains($url, 'googletagmanager.com/gtag/js'));

    // Two gtag.js loads is how a page_view gets counted twice.
    expect($gtagLoaders)->toHaveCount(1);

    $inline = implode("\n", array_column($tags['inline'], 'js'));
    expect($inline)->toContain("gtag('config','G-ABCDE12345')")
        ->and($inline)->toContain("gtag('config','AW-123456789')");
});

test('the check reports found when the storefront serves the id', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);
    $pixel = ws38PixelId();

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => $pixel],
    ])->assertOk();

    Http::fake(["https://{$store->slug}.storify.buzz*" => Http::response("<html><head><script>fbq('init','{$pixel}')</script></head></html>", 200)]);

    $this->withToken(ws38Token($owner))
        ->postJson("/api/v1/management/plugins/meta_pixel/test?store_id={$store->id}")
        ->assertOk()
        ->assertJsonPath('data.found', true)
        ->assertJsonPath('data.reachable', true);
});

test('the check reports not found when the id is absent from the raw html', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => ws38PixelId()],
    ])->assertOk();

    Http::fake(["https://{$store->slug}.storify.buzz*" => Http::response('<html><head></head><body></body></html>', 200)]);

    $this->withToken(ws38Token($owner))
        ->postJson("/api/v1/management/plugins/meta_pixel/test?store_id={$store->id}")
        ->assertOk()
        ->assertJsonPath('data.found', false)
        ->assertJsonPath('data.reachable', true);
});

test('the check is a no-op when the plugin is not connected for that store', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    Http::fake();

    $this->withToken(ws38Token($owner))
        ->postJson("/api/v1/management/plugins/meta_pixel/test?store_id={$store->id}")
        ->assertOk()
        ->assertJsonPath('data.found', false);

    // Nothing was connected, so nothing should have been fetched.
    Http::assertNothingSent();
});

test('the check never targets a host the caller supplied', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws38Store($owner);

    $this->withToken(ws38Token($owner))->putJson('/api/v1/management/plugins/meta_pixel', [
        'is_enabled' => true,
        'config' => ['pixel_id' => ws38PixelId()],
    ])->assertOk();

    Http::fake(['*' => Http::response('<html></html>', 200)]);

    $this->withToken(ws38Token($owner))
        ->postJson("/api/v1/management/plugins/meta_pixel/test?store_id={$store->id}&url=https://evil.example.com")
        ->assertOk();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "https://{$store->slug}.storify.buzz"));
});
