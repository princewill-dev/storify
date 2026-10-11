<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Daily exchange rates, for pricing a naira store through a USD gateway.
     * Keyless and public — the URL is configurable so it can be pointed
     * elsewhere without a code change, not because it is a secret.
     */
    'exchange_rates' => [
        'url' => env('EXCHANGE_RATES_URL', 'https://open.er-api.com/v6/latest/USD'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL', env('MAIL_FROM_ADDRESS')),
    ],

    /*
     * The platform's own Bachs account, used to bill Storify's subscriptions
     * when an admin selects Bachs as the billing gateway. Merchant connections
     * do not come from here — those are stored per business, encrypted, the way
     * every other gateway's are.
     *
     * Hand-managed in `.env` on the server, exactly like the Paystack keys
     * above. `BACHS_WEBHOOK_SECRET` is the signing secret of the webhook
     * endpoint created in Bachs' dashboard, not an API key; the API host is
     * chosen from the key's `sk_sandbox_` / `sk_live_` prefix, so there is
     * nothing to configure for it.
     */
    'bachs' => [
        'api_key' => env('BACHS_API_KEY'),
        'webhook_secret' => env('BACHS_WEBHOOK_SECRET'),
        // The connect endpoint's own secret, when Bachs issues it a different
        // one. Left unset it falls back to the value above, so one secret for
        // both endpoints needs no configuration — see
        // `PlatformBillingGateway::connectWebhookCredentials()`.
        'connect_webhook_secret' => env('BACHS_CONNECT_WEBHOOK_SECRET'),
    ],

];
