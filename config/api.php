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
            // The idle session window. A session is kept alive only by activity:
            // the refresh token is pushed this far forward on every rotation,
            // and the console warns and signs out when it is not. `0` restores
            // the day-based `refresh_ttl_days` behaviour above.
            'idle_ttl_minutes' => (int) env('API_MANAGEMENT_IDLE_TTL_MINUTES', 1440),
            'abilities' => ['management'],
        ],
        'admin' => [
            'access_ttl_minutes' => (int) env('API_ADMIN_ACCESS_TTL', 120),
            'refresh_ttl_days' => (int) env('API_ADMIN_REFRESH_TTL', 14),
            'idle_ttl_minutes' => (int) env('API_ADMIN_IDLE_TTL_MINUTES', 1440),
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

    /*
    |--------------------------------------------------------------------------
    | Idle warning
    |--------------------------------------------------------------------------
    |
    | How long before an idle session expires the console shows its warning.
    | The API sends this with every auth response so the client counts down
    | against the same number the server enforces.
    |
    */
    'idle_warning_seconds' => (int) env('API_SESSION_WARNING_SECONDS', 60),
];
