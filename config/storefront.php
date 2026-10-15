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
    | When true, an order can only be placed by a signed-in customer whose
    | email is verified, and checkout collects the account as part of the flow.
    | When false, an account-less order still fabricates a customer row exactly
    | as it always has.
    |
    | This is a rollover switch, not a preference, and it exists because of the
    | deploy order rather than because both behaviours are wanted. The API is
    | deployed by hand and the storefront SPA deploys on push, so the storefront
    | that ships the new checkout cannot be live before the API accepts it, and
    | the API cannot start refusing account-less orders before that storefront
    | is live. It goes: API with this off, then the SPA, then this on.
    |
    | Read in the controller rather than applied as route middleware so the
    | refusal is a readable payload error rather than a bare 401, which is what
    | lets a storefront too old to know about accounts say something useful.
    |
    | Remove both the flag and its false branch once no deployed bundle places
    | an account-less order.
    |
    */

    'checkout_requires_account' => env('STOREFRONT_CHECKOUT_REQUIRES_ACCOUNT', false),

];
