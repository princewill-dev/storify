<?php

/*
|--------------------------------------------------------------------------
| Standalone frontend origins
|--------------------------------------------------------------------------
| This app is API-only; the UI lives in separate Vue applications. Anything
| that has to build a link back to one of them — invitation mails, password
| resets, payment redirects — resolves it here.
|
| Read through config rather than env() so the values survive `config:cache`.
*/

$mainDomain = (string) env('APP_MAIN_DOMAIN', 'storify.ng');

/*
| The storefronts do not live on the main domain. Every store is served from
| its own subdomain of a separate domain — {slug}.storify.buzz — while
| management, admin and the marketing site stay on APP_MAIN_DOMAIN. The two
| are kept apart deliberately: nothing else derives from this value.
|
| The backend is not on a subdomain of either; it is its own origin, reached
| through API_BASE_URL.
*/
$storefrontDomain = (string) env('STOREFRONT_MAIN_DOMAIN', 'storify.buzz');

return [

    'main_domain' => $mainDomain,
    'storefront_main_domain' => $storefrontDomain,

    // Absolute origins. Left null, each falls back to a conventional
    // subdomain of the main domain (or a local dev port).
    'management_url' => env('MANAGEMENT_SPA_URL'),
    'admin_url' => env('ADMIN_SPA_URL'),
    'storefront_url' => env('STOREFRONT_URL'),
    'home_url' => env('HOME_URL'),
    'pos_url' => env('POS_SPA_URL'),

    // Used only when the corresponding *_URL above is not set.
    'local_ports' => [
        'management' => (int) env('MANAGEMENT_SPA_PORT', 5173),
        'admin' => (int) env('ADMIN_SPA_PORT', 5176),
        'storefront' => (int) env('STOREFRONT_SPA_PORT', 5174),
        'home' => (int) env('HOME_SPA_PORT', 5175),
    ],

];
