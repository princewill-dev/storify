<?php

use App\Models\Store;
use App\Rules\ReservedStoreSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('reserved subdomains never match the storefront routes', function () {
    [$owner, $business] = createBusinessOwner();

    // Simulate a legacy store that managed to claim a reserved slug.
    Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Reserved Store',
        'slug' => 'admin',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    $domain = config('app.main_domain');
    $routes = app('router')->getRoutes();

    $matches = fn (string $host) => $routes->match(Request::create('http://'.$host.'/'));

    foreach (['admin', 'app', 'pos', 'api', 'staff', 'account', 'dashboard', 'staging'] as $reserved) {
        expect(fn () => $matches($reserved.'.'.$domain))
            ->toThrow(NotFoundHttpException::class);
    }

    // A normal store host still matches the storefront route.
    expect(fn () => $matches('real-shop.'.$domain))->not->toThrow(NotFoundHttpException::class);
});

test('reserved and invalid store slugs are rejected by validation', function () {
    foreach (['admin', 'app', 'pos', 'api', 'www', 'status', 'Admin', 'ADMIN'] as $slug) {
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
