<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000'))
)));

// Single PCRE pattern (include delimiters, e.g. #^https://(.+\.)?example\.com$#) for previews / subdomains.
$originPattern = trim((string) env('CORS_ALLOWED_ORIGIN_PATTERN', ''));

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // Apply CORS to API endpoints (and Sanctum CSRF cookie if you use it later).
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | When supports_credentials is true, browsers require explicit origins (not *).
    | Must list every dev/prod URL the Next.js app is served from.
    */
    'allowed_origins' => $origins !== [] ? $origins : ['http://localhost:3000'],

    'allowed_origins_patterns' => $originPattern !== '' ? [$originPattern] : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => filter_var(
        env('CORS_SUPPORTS_CREDENTIALS', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),
];
