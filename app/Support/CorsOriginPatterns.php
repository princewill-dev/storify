<?php

namespace App\Support;

/**
 * Normalises the `API_ALLOWED_ORIGINS` and `API_ALLOWED_ORIGIN_PATTERNS` lists.
 *
 * CORS patterns are handed straight to `preg_match()`, delimiters and all. A
 * bare entry such as "^https://[a-z0-9-]+\.example\.ng$" therefore raises
 * "Delimiter must not be alphanumeric or backslash", and in production that
 * warning becomes an exception — every preflight returns 500 and no frontend
 * can talk to the API. Fixing the entry up is friendlier than taking the API
 * down over a paste mistake in an env file.
 *
 * Wrapping is also the form to document, for a reason that is not obvious:
 * dotenv treats a leading "#" as an inline comment, so a delimited pattern
 * written unquoted in a .env — `API_ALLOWED_ORIGIN_PATTERNS=#^https://…#` —
 * parses to an empty string. Nothing errors; the origins simply stop matching
 * and every browser call from those hosts fails as an opaque "Network Error".
 * Undelimited entries never start with "#", so they cannot fall into it.
 */
class CorsOriginPatterns
{
    /**
     * @return list<string>
     */
    public static function normalise(string $value): array
    {
        $patterns = [];

        foreach (explode(',', $value) as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            $patterns[] = self::isUsableRegex($pattern)
                ? $pattern
                : '#'.str_replace('#', '\#', $pattern).'#';
        }

        return array_values($patterns);
    }

    /**
     * Origins are compared literally, so a stray trailing slash — which the
     * browser never sends — would silently never match.
     *
     * @return list<string>
     */
    public static function origins(string $value): array
    {
        $origins = [];

        foreach (explode(',', $value) as $origin) {
            $origin = rtrim(trim($origin), '/');

            if ($origin === '') {
                continue;
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /**
     * Probing with `@` keeps a bad pattern from surfacing as an exception here,
     * where it would defeat the point of the check.
     */
    private static function isUsableRegex(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }
}
