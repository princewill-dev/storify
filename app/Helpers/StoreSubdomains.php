<?php

namespace App\Helpers;

final class StoreSubdomains
{
    /**
     * Legacy path prefixes that must never be treated as store slugs on the
     * local-dev path-based storefront routes.
     *
     * @var array<int, string>
     */
    private const LEGACY_PATH_PREFIXES = [
        'storage',
        'livewire',
        'cart',
        'checkout',
        'products',
        'services',
        'search',
        'support',
        'international-supply',
    ];

    /**
     * Route constraint for {store_subdomain}: matches any valid hostname
     * label that is not a reserved system subdomain.
     */
    public static function constraint(): string
    {
        return self::build(config('storefront.reserved_subdomains', []));
    }

    /**
     * Route constraint for the local-dev path-based storefront routes; also
     * excludes the legacy path prefixes (cart, checkout, products, ...).
     */
    public static function localConstraint(): string
    {
        return self::build(array_merge(
            config('storefront.reserved_subdomains', []),
            self::LEGACY_PATH_PREFIXES
        ));
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function build(array $names): string
    {
        $names = array_map(fn (string $name) => preg_quote(strtolower($name), '#'), $names);

        if ($names === []) {
            return '[A-Za-z0-9_\-]+';
        }

        return '(?!('.implode('|', $names).')[./])[A-Za-z0-9_\-]+';
    }
}
