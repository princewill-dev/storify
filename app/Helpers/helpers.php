<?php

use App\Helpers\UrlHelper;

if (!function_exists('store_url')) {
    /**
     * Generate a subdomain-based URL for a store
     * 
     * @param string $storeSlug
     * @param string $path
     * @param array $parameters
     * @return string
     */
    function store_url(string $storeSlug, string $path = '', array $parameters = []): string
    {
        return UrlHelper::storeUrl($storeSlug, $path, $parameters);
    }
}

if (!function_exists('store_route')) {
    /**
     * Generate a subdomain-based route URL for a store
     * 
     * @param string $routeName
     * @param string $storeSlug
     * @param array $parameters
     * @return string
     */
    function store_route(string $routeName, string $storeSlug, array $parameters = []): string
    {
        return UrlHelper::storeRoute($routeName, $storeSlug, $parameters);
    }
}
