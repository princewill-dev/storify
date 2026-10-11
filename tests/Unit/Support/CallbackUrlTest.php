<?php

use App\Support\Payments\CallbackUrl;

/*
| The return URL is the only thing the customer's browser carries back from a
| gateway, so the reference has to be on it before the gateway is called — most
| providers redirect to the URL they were given rather than decorating it.
|
| These tests pin the shapes that matter: the two ways a query string can
| already be present, the fragment that must not swallow the key, and the
| replacement that keeps the key single-valued.
*/

test('the reference is appended to a url with no query string', function () {
    expect(CallbackUrl::withReference('https://app.storify.test/subscription/callback', 'ref_ABC'))
        ->toBe('https://app.storify.test/subscription/callback?storify_ref=ref_ABC');
});

test('an existing query string is kept and joined with an ampersand', function () {
    // The storefront's own return URL already carries `provider`, and a second
    // `?` would make the reference part of the first parameter's value.
    expect(CallbackUrl::withReference(
        'https://shop.storify.test/checkout/payment/ORD-1/success?provider=bachs',
        'ref_ABC',
    ))->toBe('https://shop.storify.test/checkout/payment/ORD-1/success?provider=bachs&storify_ref=ref_ABC');
});

test('a fragment stays a fragment, and the key goes before it', function () {
    // `#` ends the query string. Appended after one, the reference would be
    // part of the fragment: never sent anywhere, never read by `route.query`.
    expect(CallbackUrl::withReference('https://app.storify.test/return#step-2', 'ref_ABC'))
        ->toBe('https://app.storify.test/return?storify_ref=ref_ABC#step-2')
        ->and(CallbackUrl::withReference('https://app.storify.test/return?a=1#step-2', 'ref_ABC'))
        ->toBe('https://app.storify.test/return?a=1&storify_ref=ref_ABC#step-2');
});

test('a reference already present is replaced, never duplicated', function () {
    // Two values under one key reach Vue Router as an array, and the page
    // stringifies it into "ref_OLD,ref_ABC" — a reference matching no payment.
    // Replacing also stops a client-supplied `callback_url` naming a payment of
    // its own choosing on the return page.
    expect(CallbackUrl::withReference('https://app.storify.test/return?storify_ref=ref_OLD', 'ref_ABC'))
        ->toBe('https://app.storify.test/return?storify_ref=ref_ABC');
});

test('nothing to say leaves the url alone', function () {
    $url = 'https://app.storify.test/subscription/callback';

    expect(CallbackUrl::withReference($url, ''))->toBe($url)
        ->and(CallbackUrl::withReference($url, '   '))->toBe($url)
        ->and(CallbackUrl::withReference($url, null))->toBe($url);
});

test('a string with no path is left alone rather than made malformed', function () {
    // Not a reachable shape from either call site, but guessing a host for it
    // would produce a URL the provider cannot redirect to at all.
    expect(CallbackUrl::withReference('?a=1', 'ref_ABC'))->toBe('?a=1')
        ->and(CallbackUrl::withReference('', 'ref_ABC'))->toBe('');
});

test('the key is ours, not one a provider also writes', function () {
    // Paystack appends a `reference` of its own. Reusing that name is what
    // would create the duplicate above on the one provider that already works.
    expect(CallbackUrl::QUERY_KEY)->toBe('storify_ref');
});
