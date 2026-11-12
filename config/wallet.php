<?php

// Customer wallet, booking deposit and private SePay "Live lab" policy (live-wallet-policy.ts in the API package).
// Every value is read from the server environment; nothing here is a secret. The lab is refused in production.
return [
    // INTERNAL_WALLET or SIMULATED_WALLET turns on wallet deposits at booking time; empty keeps today's behaviour.
    'mode' => env('BEAUTYBOOK_PAYMENT_MODE', ''),
    // Direct deposits: the customer pays the business's own bank account and a signed SePay receipt confirms it
    // (direct-deposit.service.ts). The key encrypts each connection's webhook secret: 64 hex characters, stable, never in git.
    'direct' => [
        'enabled' => filter_var(env('DIRECT_DEPOSIT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'encryption_key' => env('SEPAY_CONNECTION_ENCRYPTION_KEY', ''),
        // Production takes real money: SePay's Test mode connections are refused unless DIRECT_DEPOSIT_ALLOW_TEST=true, and a live connection
        // needs an https APP_URL (SePay rejects http webhook addresses).
        'production' => env('APP_ENV') === 'production',
        'allow_test' => env('DIRECT_DEPOSIT_ALLOW_TEST') === null ? env('APP_ENV') !== 'production' : filter_var(env('DIRECT_DEPOSIT_ALLOW_TEST'), FILTER_VALIDATE_BOOL),
    ],
    'live_lab' => [
        'isolated' => filter_var(env('WALLET_LIVE_LAB_ISOLATED', false), FILTER_VALIDATE_BOOL),
        'enabled' => filter_var(env('WALLET_LIVE_LAB_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Comma-separated numeric ids of the accounts and businesses allowed to top up / spend in the lab.
        'user_ids' => env('WALLET_LIVE_LAB_USER_IDS', ''),
        'business_ids' => env('WALLET_LIVE_LAB_BUSINESS_IDS', ''),
        'webhook_secret' => env('WALLET_LIVE_LAB_WEBHOOK_SECRET', ''),
        'bank_code' => env('WALLET_LIVE_LAB_BANK_CODE', ''),
        'account_number' => env('WALLET_LIVE_LAB_ACCOUNT_NUMBER', ''),
        'account_name' => env('WALLET_LIVE_LAB_ACCOUNT_NAME', ''),
        'min_topup_vnd' => env('WALLET_LIVE_LAB_MIN_TOPUP_VND', '1000'),
        'max_topup_vnd' => env('WALLET_LIVE_LAB_MAX_TOPUP_VND', '50000'),
        'max_total_vnd' => env('WALLET_LIVE_LAB_MAX_TOTAL_VND', '500000'),
    ],
];
