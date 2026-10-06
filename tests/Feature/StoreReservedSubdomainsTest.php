<?php

use App\Models\Store;
use App\Rules\ReservedStoreSlug;
use Illuminate\Support\Facades\Validator;

/*
| The API serves one host and no subdomain routes any more — the storefront,
| management and admin are standalone SPAs on their own subdomains. The
| reservation therefore has to hold at the data layer, which is where a store
| could otherwise claim a slug that collides with a system subdomain. That is
| what the two tests below cover.
*/

test('reserved and invalid store slugs are rejected by validation', function () {
    // Includes the hosts the SPAs themselves occupy (app, office), so a store
    // can never shadow the management or admin console.
    foreach (['admin', 'app', 'office', 'pos', 'api', 'www', 'manage', 'status', 'Admin', 'ADMIN'] as $slug) {
        expect(Validator::make(['slug' => $slug], ['slug' => [new ReservedStoreSlug]])->fails())->toBeTrue();
    }

    foreach (['my-store', 'store123', 'a', 'ab-artworld', 'art-world-2'] as $slug) {
        expect(Validator::make(['slug' => $slug], ['slug' => [new ReservedStoreSlug]])->fails())->toBeFalse();
    }

    foreach (['-bad-', 'bad_slug', 'bad.slug', 'bad-'] as $slug) {
        expect(Validator::make(['slug' => $slug], ['slug' => [new ReservedStoreSlug]])->fails())->toBeTrue();
    }
});

test('auto-generated store slugs avoid reserved subdomains and stay hostname safe', function () {
    [$owner, $business] = createBusinessOwner();

    $reservedName = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Admin',
        'status' => Store::STATUS_ACTIVE,
    ]);

    expect($reservedName->slug)->not->toBe('admin')
        ->and($reservedName->slug)->toMatch('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/');

    $normal = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'My Cool Store!',
        'status' => Store::STATUS_ACTIVE,
    ]);

    expect($normal->slug)->toBe('my-cool-store');
});
