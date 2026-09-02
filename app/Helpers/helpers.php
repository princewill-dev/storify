<?php

use App\Helpers\UrlHelper;

if (! function_exists('store_url')) {
    /**
     * Generate a subdomain-based URL for a store
     */
    function store_url(string $storeSlug, string $path = '', array $parameters = []): string
    {
        return UrlHelper::storeUrl($storeSlug, $path, $parameters);
    }
}

if (! function_exists('store_route')) {
    /**
     * Generate a subdomain-based route URL for a store
     */
    function store_route(string $routeName, string $storeSlug, array $parameters = []): string
    {
        return UrlHelper::storeRoute($routeName, $storeSlug, $parameters);
    }
}
