<?php

use App\Models\Store;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;

function storefrontContentStore(array $attributes = []): Store
{
    [, $business] = createBusinessOwner();

    return Store::create(array_merge([
        'business_id' => $business->id,
        'name' => 'Shopfront',
        'slug' => 'shopfront',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $attributes));
}

function storefrontContentPage(Store $store, string $slug, array $attributes = []): StorefrontPage
{
    return StorefrontPage::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'title' => ucfirst($slug),
        'slug' => $slug,
        'content' => '<p>Content for '.$slug.'</p>',
        'is_published' => true,
        'show_in_footer' => true,
    ], $attributes));
}

test('home exposes storefront branding, socials and footer pages without drafts', function () {
    $store = storefrontContentStore([
        'instagram_url' => 'https://instagram.com/shopfront',
        'facebook_url' => 'https://facebook.com/shopfront',
        'twitter_url' => null,
        'tiktok_url' => 'https://tiktok.com/@shopfront',
    ]);

    StorefrontSetting::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'site_title' => 'Shopfront — everyday essentials',
    ]);

    storefrontContentPage($store, 'about');
    storefrontContentPage($store, 'terms', ['show_in_footer' => false]);
    storefrontContentPage($store, 'privacy', ['is_published' => false]);
    storefrontContentPage($store, 'careers');

    $response = $this->getJson('/api/v1/storefront/'.$store->slug.'/home');

    $response->assertOk()
        ->assertJsonPath('data.store.site_title', 'Shopfront — everyday essentials')
        ->assertJsonPath('data.store.socials.instagram', 'https://instagram.com/shopfront')
        ->assertJsonPath('data.store.socials.facebook', 'https://facebook.com/shopfront')
        ->assertJsonPath('data.store.socials.twitter', null)
        ->assertJsonPath('data.store.socials.tiktok', 'https://tiktok.com/@shopfront')
        ->assertJsonCount(2, 'data.footer_pages')
        ->assertJsonPath('data.footer_pages.0.title', 'About')
        ->assertJsonPath('data.footer_pages.0.path', '/about')
        ->assertJsonPath('data.footer_pages.1.title', 'Careers')
        ->assertJsonPath('data.footer_pages.1.path', '/pages/careers');
});

test('a published page is served and a draft or unknown slug is a page_not_found', function () {
    $store = storefrontContentStore();

    storefrontContentPage($store, 'about');
    storefrontContentPage($store, 'privacy', ['is_published' => false]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/pages/about')
        ->assertOk()
        ->assertJsonPath('data.title', 'About')
        ->assertJsonPath('data.slug', 'about')
        ->assertJsonPath('data.path', '/about')
        ->assertJsonPath('data.content', '<p>Content for about</p>');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/pages/privacy')
        ->assertNotFound()
        ->assertJsonPath('errors.page.0', 'page_not_found');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/pages/missing')
        ->assertNotFound()
        ->assertJsonPath('errors.page.0', 'page_not_found');
});

test('a page from another store is not reachable from this storefront', function () {
    $store = storefrontContentStore();
    $other = storefrontContentStore([
        'slug' => 'other-shop',
        'name' => 'Other Shop',
    ]);

    storefrontContentPage($other, 'about');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/pages/about')
        ->assertNotFound()
        ->assertJsonPath('errors.page.0', 'page_not_found');
});

test('seo falls back to the store name, description and logo', function () {
    $store = storefrontContentStore([
        'name' => 'Shopfront',
        'description' => str_repeat('Handmade goods. ', 20),
        'logo_path' => 'stores/logos/shopfront.png',
    ]);

    storefrontContentPage($store, 'about');
    storefrontContentPage($store, 'privacy', ['is_published' => false]);

    $response = $this->getJson('/api/v1/storefront/'.$store->slug.'/seo');

    $response->assertOk()
        ->assertJsonPath('data.site.name', 'Shopfront')
        ->assertJsonPath('data.site.title', 'Shopfront')
        ->assertJsonPath('data.site.image', asset('storage/stores/logos/shopfront.png'))
        ->assertJsonPath('data.site.url', 'https://shopfront.'.config('frontend.storefront_main_domain'))
        ->assertJsonPath('data.site.type', 'website')
        ->assertJsonCount(1, 'data.pages')
        ->assertJsonPath('data.pages.0.path', '/about')
        ->assertJsonPath('data.pages.0.title', 'About')
        ->assertJsonPath('data.pages.0.description', 'Content for about');

    // The fallback description is cut to a meta-sized excerpt.
    expect(mb_strlen($response->json('data.site.description')))->toBeLessThanOrEqual(160);
});

test('seo prefers the configured title, description and og image', function () {
    $store = storefrontContentStore([
        'name' => 'Shopfront',
        'description' => 'A long store description.',
        'logo_path' => 'stores/logos/shopfront.png',
    ]);

    StorefrontSetting::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'site_title' => 'Shopfront — everyday essentials',
        'meta_description' => 'Everyday essentials, delivered.',
        'og_image_path' => 'storefront/og/shopfront.png',
    ]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/seo')
        ->assertOk()
        ->assertJsonPath('data.site.title', 'Shopfront — everyday essentials')
        ->assertJsonPath('data.site.description', 'Everyday essentials, delivered.')
        ->assertJsonPath('data.site.image', asset('storage/storefront/og/shopfront.png'));
});

test('an offline store answers storefront_offline on both content endpoints', function () {
    $store = storefrontContentStore(['has_website' => false]);

    storefrontContentPage($store, 'about');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/seo')
        ->assertNotFound()
        ->assertJsonPath('errors.store.0', 'storefront_offline');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/pages/about')
        ->assertNotFound()
        ->assertJsonPath('errors.store.0', 'storefront_offline');
});
