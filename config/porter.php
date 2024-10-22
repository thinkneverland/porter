<?php

return [
    's3' => [
        // Target bucket (used only for cloning operations)
        'target_bucket'     => env('AWS_BUCKET'),
        'target_region'     => env('AWS_DEFAULT_REGION'),
        'target_access_key' => env('AWS_ACCESS_KEY_ID'),
        'target_secret_key' => env('AWS_SECRET_ACCESS_KEY'),
        'target_url'        => env('AWS_URL'),
        'target_endpoint'   => env('AWS_ENDPOINT', null),  // Endpoint for target (optional)

        // Source bucket (used only for cloning operations)
        'source_bucket'     => env('AWS_SOURCE_BUCKET'),
        'source_region'     => env('AWS_SOURCE_REGION'),
        'source_access_key' => env('AWS_SOURCE_ACCESS_KEY_ID'),
        'source_secret_key' => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
        'source_url'        => env('AWS_SOURCE_URL'),
        'source_endpoint'   => env('AWS_SOURCE_ENDPOINT', null),  // Endpoint for source (optional)
    ],

    // Alternate S3 Export Configuration
    'export_alt' => [
        'enabled'                 => env('EXPORT_ALT_AWS_ENABLED', false),
        'bucket'                  => env('EXPORT_ALT_AWS_BUCKET', null),
        'region'                  => env('EXPORT_ALT_AWS_REGION', null),
        'access_key'              => env('EXPORT_ALT_AWS_ACCESS_KEY_ID', null),
        'secret_key'              => env('EXPORT_ALT_AWS_SECRET_ACCESS_KEY', null),
        'url'                     => env('EXPORT_ALT_AWS_URL', null),
        'endpoint'                => env('EXPORT_ALT_AWS_ENDPOINT', null), // Optional for custom S3 services like MinIO
        'use_path_style_endpoint' => env('EXPORT_ALT_AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],

    'export' => [
        'multipart'  => env('EXPORT_MULTIPART', false),
        'expiration' => env('EXPORT_AWS_EXPIRATION', 3600),  // Expiration time in seconds
    ],
];
