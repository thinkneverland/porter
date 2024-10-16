<?php

return [

    /*
    |--------------------------------------------------------------------------
    | S3 Storage Settings
    |--------------------------------------------------------------------------
    |
    | Configuration settings for primary and secondary S3 instances.
    |
    */

    'primaryS3' => [
        'bucket'         => env('AWS_BUCKET'),
        'region'         => env('AWS_DEFAULT_REGION'),
        'key'            => env('AWS_ACCESS_KEY_ID'),
        'secret'         => env('AWS_SECRET_ACCESS_KEY'),
        'url'            => env('AWS_URL'),
        'endpoint'       => env('AWS_ENDPOINT'),
        'use_path_style' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],

    'sourceS3' => [
        'bucket'         => env('AWS_SOURCE_BUCKET'),
        'region'         => env('AWS_DEFAULT_SOURCE_REGION'),
        'key'            => env('AWS_SOURCE_ACCESS_KEY_ID'),
        'secret'         => env('AWS_SOURCE_SECRET_ACCESS_KEY'),
        'url'            => env('AWS_SOURCE_URL'),
        'endpoint'       => env('AWS_SOURCE_ENDPOINT'),
        'use_path_style' => env('AWS_SOURCE_USE_PATH_STYLE_ENDPOINT', false),
    ],
];
