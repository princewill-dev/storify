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

    /*
    |--------------------------------------------------------------------------
    | Checkout requires an account
    |--------------------------------------------------------------------------
    |
    | On, an order can only be placed by a signed-in customer whose email is
    | verified, and checkout collects that account as part of the order form —
    | one form serving both purposes. Off, an account-less order fabricates a
    | customer row with a password nobody chose, exactly as it always did.
    |
    | On is the settled position, not a stage of a rollout: the platform is
    | merchant of record and an order has to belong to somebody. The switch is
    | kept only because turning it off is how a bad release is backed out — the
    | API deploys by hand while the storefront deploys on push, so if a
    | storefront bug ever has customers unable to register, refusing their
    | orders is the wrong failure and this is the one lever that ends it.
    |
    | That direction is the safe one. Setting it back to false lets a
    | storefront that knows nothing about accounts keep taking orders; setting
    | it to true while such a storefront is live refuses every sale it
    | attempts. Ship the SPA first, then the API.
    |
    | Read in the controller rather than applied as route middleware so the
    | refusal is a readable payload error rather than a bare 401, which is what
    | lets a storefront too old to know about accounts say something useful.
    |
    | Remove both the flag and the false branch in PlaceStorefrontOrder once no
    | deployed bundle places an account-less order.
    |
    */

    'checkout_requires_account' => env('STOREFRONT_CHECKOUT_REQUIRES_ACCOUNT', true),

];
