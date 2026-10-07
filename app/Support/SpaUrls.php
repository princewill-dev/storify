<?php

namespace App\Support;

/**
 * Absolute URLs into the standalone frontend applications.
 *
 * The API has to link back to a SPA in a few places — invitation acceptance,
 * password reset, download pages. Those links used to point at legacy Blade
 * routes; they now resolve here.
 *
 * Values come from config/frontend.php rather than env() so they keep working
 * under `config:cache`, and each falls back to a conventional subdomain of the
 * main domain (or a local dev port) when not configured.
 */
final class SpaUrls
{
    public static function management(string $path = ''): string
    {
        return self::base('management', 'app').self::path($path);
    }

    /**
     * The platform admin console is served at office.<main domain>.
     */
    public static function admin(string $path = ''): string
    {
        return self::base('admin', 'office').self::path($path);
    }

    /**
     * A store's own storefront. Each store is served from its own subdomain,
     * so the link has to carry the slug — there is no single storefront origin.
     *
     * The subdomains hang off `storefront_main_domain` (storify.buzz), which is
     * a different domain from the one management and admin use.
     *
     * A configured STOREFRONT_URL wins, which is what a single-store
     * deployment (or a local dev server reached via ?store=) wants. Note that
     * it drops the slug — it identifies one origin, not a family of them.
     */
    public static function storefront(string $storeSlug, string $path = ''): string
    {
        $configured = config('frontend.storefront_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/').self::path($path);
        }

        if (self::isLocalDevelopment()) {
            $port = (int) config('frontend.local_ports.storefront', 5174);

            return "http://{$storeSlug}.localhost:{$port}".self::path($path);
        }

        $domain = (string) config('frontend.storefront_main_domain', config('frontend.main_domain', 'storify.ng'));

        return 'https://'.$storeSlug.'.'.$domain.self::path($path);
    }

    public static function home(string $path = ''): string
    {
        return self::base('home', null).self::path($path);
    }

    /**
     * Configured origin if present, otherwise a local dev port when running
     * locally, otherwise the subdomain — or the apex domain when the app has
     * no subdomain of its own (the marketing home).
     */
    private static function base(string $app, ?string $subdomain): string
    {
        $configured = config("frontend.{$app}_url");

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        if (self::isLocalDevelopment()) {
            $port = (int) config("frontend.local_ports.{$app}", 5173);

            return "http://localhost:{$port}";
        }

        $domain = (string) config('frontend.main_domain', 'storify.ng');

        return $subdomain ? "https://{$subdomain}.{$domain}" : "https://{$domain}";
    }

    /**
     * Local development is APP_ENV=local AND a development main domain.
     *
     * Both are required deliberately. A server left with APP_ENV=local on a
     * real domain is a common deployment mistake, and branching on the
     * environment alone published links pointing at localhost — unreachable for
     * everyone. Requiring a dev-looking domain means such a server still
     * produces the correct public URL.
     */
    private static function isLocalDevelopment(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        $domain = strtolower((string) config('frontend.main_domain', ''));

        return $domain === ''
            || str_ends_with($domain, '.test')
            || str_ends_with($domain, '.local')
            || str_contains($domain, 'localhost');
    }

    private static function path(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return '/'.ltrim($path, '/');
    }
}
