<?php

// Device push through Firebase Cloud Messaging HTTP v1 (push-policy.ts / fcm.service.ts in the API package).
// Credentials stay outside the repository: point FCM_SERVICE_ACCOUNT_FILE at a service-account JSON on the server.
return [
    'enabled' => (bool) env('PUSH_ENABLED', false),
    'project_id' => env('FCM_PROJECT_ID'),
    'service_account_file' => env('FCM_SERVICE_ACCOUNT_FILE'),
    'token_url' => env('FCM_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
    'send_url' => env('FCM_SEND_URL', 'https://fcm.googleapis.com/v1/projects/%s/messages:send'),
    // Preview/test only: serve protocol-faithful stand-ins for Google OAuth and FCM from this app (ignored outside local/testing).
    'trial_sink' => (bool) env('PUSH_TRIAL_SINK', false),
    // Public web settings of the Firebase project, handed to the browser so it can take its own FCM token (they identify the project, they
    // are not secrets; the service account and its private key never leave the server). All four must be set for the browser channel to open.
    'web' => [
        'api_key' => env('FCM_WEB_API_KEY'),
        'app_id' => env('FCM_WEB_APP_ID'),
        'sender_id' => env('FCM_WEB_SENDER_ID'),
        'vapid_key' => env('FCM_WEB_VAPID_KEY'),
    ],
    'channel' => 'beautybook-bookings',
    'max_attempts' => 8,
];
