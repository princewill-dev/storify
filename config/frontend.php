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
| The domain every store's own subdomain hangs from — {slug}.<this>.
|
| This used to be a separate domain (storify.buzz) so a store slug could never
| collide with app./office./www. The wildcard now lives on the main domain
| instead, and those names are held clear by the reserved-slug rules rather
| than by a domain boundary — see config/storefront.php, which the storefront
| SPA mirrors. Keep the key separate from `main_domain`: a deployment still
| publishing on another domain, or a single-store one, overrides this alone.
|
| The backend is not a subdomain of this; it is its own origin, reached through
| API_BASE_URL.
*/
$storefrontDomain = (string) env('STOREFRONT_MAIN_DOMAIN', 'storify.ng');

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
