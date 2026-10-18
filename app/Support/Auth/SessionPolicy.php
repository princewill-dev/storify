<?php

namespace App\Support\Auth;

use Illuminate\Support\Carbon;

/**
 * How long a console session lives, in one place.
 *
 * Three numbers used to be implied by four different config reads: the access
 * token's lifetime, the refresh token's lifetime, and — once this feature
 * arrived — the idle window the UI counts down. They are answered together
 * here so a deploy cannot set one on the server and ship another to the client.
 *
 * ## Two clocks
 *
 * The refresh token's `expires_at` **is** the idle deadline. It is pushed
 * forward on every rotation, and the client rotates whenever its access token
 * expires — so an actively used session never reaches it, and an abandoned one
 * does.
 *
 * For that to hold, the access token must expire well before the idle
 * deadline. `accessTtlMinutes()` caps it at half the idle window (and at
 * Sanctum's own global cap), which guarantees a request that is still being
 * made will rotate in time.
 *
 * `idle_ttl_minutes <= 0` means "no idle window configured": the day-based
 * `refresh_ttl_days` behaviour returns, unchanged, which is the escape hatch
 * for an app that has not adopted this.
 */
final class SessionPolicy
{
    /**
     * The idle deadline in minutes.
     *
     * When no idle window is configured this reports the day-based lifetime
     * converted to minutes, so the client still has a number to count down
     * from — it is simply the old, much longer one.
     */
    public static function idleMinutes(string $app): int
    {
        if (self::hasIdleWindow($app)) {
            return (int) config("api.apps.{$app}.idle_ttl_minutes");
        }

        return self::fallbackDays($app) * 1440;
    }

    /**
     * The expiry stamped on a newly issued or rotated refresh token.
     */
    public static function refreshExpiry(string $app): Carbon
    {
        if (self::hasIdleWindow($app)) {
            return now()->addMinutes((int) config("api.apps.{$app}.idle_ttl_minutes"));
        }

        return now()->addDays(self::fallbackDays($app));
    }

    /**
     * How long an access token should live.
     *
     * The smallest of:
     *  - the app's configured access TTL;
     *  - half the idle window, so a live session rotates before it expires;
     *  - Sanctum's global `expiration`, which the guard enforces regardless.
     */
    public static function accessTtlMinutes(string $app): int
    {
        $ttl = max(1, (int) config("api.apps.{$app}.access_ttl_minutes", 120));

        if (self::hasIdleWindow($app)) {
            $ttl = min($ttl, max(1, (int) ceil(((int) config("api.apps.{$app}.idle_ttl_minutes")) / 2)));
        }

        $sanctumCap = (int) config('sanctum.expiration', 0);

        if ($sanctumCap > 0) {
            $ttl = min($ttl, $sanctumCap);
        }

        return max(1, $ttl);
    }

    /**
     * How long before the deadline the console warns, in seconds.
     */
    public static function warningSeconds(): int
    {
        return max(0, (int) config('api.idle_warning_seconds', 60));
    }

    public static function hasIdleWindow(string $app): bool
    {
        return (int) config("api.apps.{$app}.idle_ttl_minutes", 0) > 0;
    }

    private static function fallbackDays(string $app): int
    {
        return max(1, (int) config("api.apps.{$app}.refresh_ttl_days", 30));
    }
}
