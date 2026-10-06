<?php

use App\Support\CorsOriginPatterns;

test('origin patterns without delimiters are wrapped', function () {
    // Laravel passes these straight to preg_match(), which rejects a bare
    // pattern — the resulting warning becomes a 500 on every preflight.
    expect(CorsOriginPatterns::normalise('^https://[a-z0-9-]+\.storify\.ng$,^null$'))
        ->toBe(['#^https://[a-z0-9-]+\.storify\.ng$#', '#^null$#']);
});

test('origin patterns that are already delimited are left alone', function () {
    expect(CorsOriginPatterns::normalise('#^https://a$#,~^https://b$~i'))
        ->toBe(['#^https://a$#', '~^https://b$~i']);
});

test('blank origin pattern entries are dropped', function () {
    expect(CorsOriginPatterns::normalise(' , , '))->toBe([]);
});

test('trailing slashes are trimmed from allowed origins', function () {
    // Browsers never send a trailing slash, so an untrimmed origin silently
    // never matches.
    expect(CorsOriginPatterns::origins('https://storify.ng/, https://app.storify.ng/'))
        ->toBe(['https://storify.ng', 'https://app.storify.ng']);
});

test('duplicate allowed origins are collapsed', function () {
    expect(CorsOriginPatterns::origins('https://storify.ng,https://storify.ng'))
        ->toBe(['https://storify.ng']);
});

test('a configured preflight is answered rather than failing', function () {
    config([
        'cors.allowed_origins' => ['https://storify.ng'],
        'cors.allowed_origins_patterns' => CorsOriginPatterns::normalise('#^https://[a-z0-9-]+\.storify\.ng$#,#^null$#'),
        'cors.supports_credentials' => true,
    ]);

    $this->call('OPTIONS', '/api/v1/admin/auth/login', [], [], [], [
        'HTTP_ORIGIN' => 'https://admin.storify.ng',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://admin.storify.ng');
});
