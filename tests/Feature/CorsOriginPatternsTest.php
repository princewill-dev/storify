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

/*
| The example we ship in .env.example has to survive dotenv, not just read
| correctly. A value starting with "#" is an inline comment to dotenv, so a
| delimited pattern written unquoted parses to nothing — and the failure is
| silent: the origins simply stop matching and every browser call from them
| fails as an opaque "Network Error". Guard the documented form.
*/
test('the pattern example in .env.example parses to working patterns', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect(preg_match('/^# API_ALLOWED_ORIGIN_PATTERNS=(.+)$/m', $example, $matches))->toBe(1);

    $value = trim($matches[1]);

    expect($value)->not->toStartWith('#');

    $patterns = CorsOriginPatterns::normalise($value);

    $matches_origin = fn (string $origin): bool => collect($patterns)
        ->contains(fn (string $pattern): bool => preg_match($pattern, $origin) === 1);

    expect($patterns)->toHaveCount(3)
        ->and($matches_origin('https://swift-one.storify.buzz'))->toBeTrue()
        ->and($matches_origin('https://app.storify.ng'))->toBeTrue()
        ->and($matches_origin('null'))->toBeTrue()
        ->and($matches_origin('https://evil-buzz.attacker.com'))->toBeFalse()
        ->and($matches_origin('https://storify.buzz.attacker.com'))->toBeFalse();
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
