<?php

return [
    'service_url' => env('ABSA_SERVICE_URL', 'http://host.docker.internal:8766'),
    'token' => env('ABSA_SERVICE_TOKEN', ''),
    'worker_enabled' => env('ABSA_WORKER_ENABLED', false),
    'interval_ms' => env('ABSA_WORKER_INTERVAL_MS', 5000),
    'batch_size' => env('ABSA_BATCH_SIZE', 4),
    'timeout_ms' => env('ABSA_REQUEST_TIMEOUT_MS', 60000),
    'max_attempts' => env('ABSA_MAX_ATTEMPTS', 5),
    'lease_ms' => env('ABSA_LEASE_MS', 180000),
];
