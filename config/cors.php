<?php

use App\Support\CorsOriginPatterns;

// Read the env directly: calling config() inside a config file can run before
// the other config file has loaded.
$allowedOrigins = CorsOriginPatterns::origins((string) env('API_ALLOWED_ORIGINS', ''));

// Regex patterns (e.g. "#^https://[a-z0-9-]+\.storify\.ng$#") for wildcard
// store subdomains, which cannot be expressed as exact origins. Delimiters are
// required; undelimited entries are wrapped rather than allowed to 500.
$allowedOriginPatterns = CorsOriginPatterns::normalise((string) env('API_ALLOWED_ORIGIN_PATTERNS', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Set API_ALLOWED_ORIGINS (comma-separated) to lock this down; falls back to "*".
    'allowed_origins' => $allowedOrigins !== [] ? $allowedOrigins : ['*'],

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => $allowedOrigins !== [],

];
