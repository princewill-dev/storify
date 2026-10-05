<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API client applications
    |--------------------------------------------------------------------------
    |
    | Each standalone frontend authenticates with a Sanctum access token that
    | carries an "audience" ability and a per-application lifetime. Refresh
    | tokens are opaque, rotated, and stored hashed in `refresh_tokens`.
    |
    */
    'apps' => [
        'management' => [
            'access_ttl_minutes' => (int) env('API_MANAGEMENT_ACCESS_TTL', 120),
            'refresh_ttl_days' => (int) env('API_MANAGEMENT_REFRESH_TTL', 30),
            'abilities' => ['management'],
        ],
        'admin' => [
            'access_ttl_minutes' => (int) env('API_ADMIN_ACCESS_TTL', 120),
            'refresh_ttl_days' => (int) env('API_ADMIN_REFRESH_TTL', 14),
            'abilities' => ['admin'],
        ],
        'customer' => [
            'access_ttl_minutes' => (int) env('API_CUSTOMER_ACCESS_TTL', 240),
            'refresh_ttl_days' => (int) env('API_CUSTOMER_REFRESH_TTL', 30),
            'abilities' => ['customer'],
        ],
        'pos' => [
            'access_ttl_minutes' => (int) env('POS_TOKEN_EXPIRY_MINUTES', 480),
            'refresh_ttl_days' => (int) env('API_POS_REFRESH_TTL', 30),
            'abilities' => ['pos'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of allowed browser origins (management, admin,
    | storefront and marketing apps). When empty, CORS falls back to "*"
    | without credentials.
    |
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('API_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Refresh token cookie
    |--------------------------------------------------------------------------
    |
    | Optionally mirror the refresh token into an httpOnly cookie scoped to the
    | API. Requires the frontend origin(s) to be listed above with credentials.
    |
    */
    'refresh_cookie' => [
        'enabled' => (bool) env('API_REFRESH_COOKIE', false),
        'name' => 'storify_refresh',
        'path' => '/api/v1/auth',
        'same_site' => env('API_REFRESH_COOKIE_SAMESITE', 'lax'),
    ],
];
