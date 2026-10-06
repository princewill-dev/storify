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
     * A configured STOREFRONT_URL wins, which is what a single-store
     * deployment (or a local dev server reached via ?store=) wants.
     */
    public static function storefront(string $storeSlug, string $path = ''): string
    {
        $configured = config('frontend.storefront_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/').self::path($path);
        }

        if (app()->environment('local')) {
            $port = (int) config('frontend.local_ports.storefront', 5174);

            return "http://{$storeSlug}.localhost:{$port}".self::path($path);
        }

        return 'https://'.$storeSlug.'.'.config('frontend.main_domain', 'storify.ng').self::path($path);
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

        if (app()->environment('local')) {
            $port = (int) config("frontend.local_ports.{$app}", 5173);

            return "http://localhost:{$port}";
        }

        $domain = (string) config('frontend.main_domain', 'storify.ng');

        return $subdomain ? "https://{$subdomain}.{$domain}" : "https://{$domain}";
    }

    private static function path(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return '/'.ltrim($path, '/');
    }
}
