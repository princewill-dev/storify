<?php

namespace App\Helpers;

class UrlHelper
{
    /**
     * Generate a subdomain-based URL for a store
     * 
     * @param string $storeSlug
     * @param string $path
     * @param array $parameters
     * @return string
     */
    public static function storeUrl(string $storeSlug, string $path = '', array $parameters = []): string
    {
        $scheme = request()->secure() ? 'https' : 'http';
        $appUrl = config('app.url');
        $baseDomain = config('app.main_domain', parse_url($appUrl, PHP_URL_HOST));
        $port = parse_url($appUrl, PHP_URL_PORT);
        
        $domainWithPort = $port ? "{$baseDomain}:{$port}" : $baseDomain;
        
        $url = "{$scheme}://{$storeSlug}.{$domainWithPort}";
        
        if ($path) {
            $url .= '/' . ltrim($path, '/');
        }
        
        if (!empty($parameters)) {
            $url .= '?' . http_build_query($parameters);
        }
        
        return $url;
    }
    
    /**
     * Generate a subdomain-based route URL for a store
     * 
     * @param string $routeName
     * @param string $storeSlug
     * @param array $parameters
     * @return string
     */
    public static function storeRoute(string $routeName, string $storeSlug, array $parameters = []): string
    {
        // Temporarily set the subdomain for route generation
        $scheme = request()->secure() ? 'https' : 'http';
        $appUrl = config('app.url');
        $baseDomain = config('app.main_domain', parse_url($appUrl, PHP_URL_HOST));
        $port = parse_url($appUrl, PHP_URL_PORT);
        
        $domainWithPort = $port ? "{$baseDomain}:{$port}" : $baseDomain;
        
        // Build the route with subdomain
        try {
            // Add store_subdomain to parameters for route generation
            $parameters['store_subdomain'] = $storeSlug;
            $path = route($routeName, $parameters, false); // Get relative path
            
            // Remove store_subdomain from the path if it appears
            $path = str_replace(['/' . $storeSlug], '', $path);
            
            return "{$scheme}://{$storeSlug}.{$domainWithPort}{$path}";
        } catch (\Exception $e) {
            // Fallback to manual URL construction
            return self::storeUrl($storeSlug, '', $parameters);
        }
    }
}
