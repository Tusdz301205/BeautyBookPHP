<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // SePay webhook contract from the API package: HMAC-SHA256 over "{timestamp}.{rawBody}". Never defaulted.
    'sepay' => [
        'enabled' => env('SEPAY_WEBHOOK_ENABLED', false),
        'environment' => env('SEPAY_ENVIRONMENT'),
        'secret' => env('SEPAY_WEBHOOK_SECRET', ''),
        'gateway' => env('SEPAY_WEBHOOK_BANK_GATEWAY', ''),
        'account_number' => env('SEPAY_WEBHOOK_ACCOUNT_NUMBER', ''),
        // Optional allowlist of SePay's published source addresses for every webhook route (comma separated IPs or CIDR). Empty = no filter.
        'allowed_ips' => env('SEPAY_ALLOWED_IPS', ''),
    ],

    // Google Identity Services (ID-token flow): only the public client id is needed, no client secret.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'jwks_url' => env('GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
