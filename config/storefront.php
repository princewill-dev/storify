<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reserved store subdomains
    |--------------------------------------------------------------------------
    |
    | Subdomains in this list can never resolve to a storefront and are
    | rejected as store slugs. They are used to build the negative lookahead
    | in the storefront route constraints and by the ReservedStoreSlug rule.
    |
    */

    'reserved_subdomains' => [
        'www',
        'api',
        'app',
        'admin',
        'pos',
        'manage',
        'management',
        'office',
        'staff',
        'account',
        'dashboard',
        'storefront',
        'cdn',
        'assets',
        'static',
        'mail',
        'status',
        'docs',
        'staging',
    ],

];
