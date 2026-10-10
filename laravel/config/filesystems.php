<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
        // Cloudflare R2 is S3-compatible
        // Shared-hosting friendly public disk: a plain folder inside the web root (no `storage:link` / symlinks needed).
        'uploads' => [
            'driver' => 'local',
            'root' => env('UPLOADS_PATH', public_path('uploads')),
            'url' => env('UPLOADS_URL', rtrim((string) env('APP_URL'), '/').'/uploads'),
            'visibility' => 'public',
            'throw' => false,
        ],


        // Backblaze B2 via its S3-compatible API. Bucket stays PRIVATE: files are served only through the
        // backend (signed download route / short-lived presigned redirects). No ACLs are sent (B2 ignores them).
        // Enable with UPLOAD_DISK=b2 and UPLOAD_PRIVATE_DISK=b2.
        'b2' => [
            'driver' => 's3',
            'key' => env('B2_KEY_ID'),
            'secret' => env('B2_APPLICATION_KEY'),
            'region' => env('B2_REGION', 'eu-central-003'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT', 'https://s3.eu-central-003.backblazeb2.com'),
            'use_path_style_endpoint' => true,
            // Newer AWS SDK versions add checksum headers B2 rejects; only send them when an operation requires it.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => true,
        ],

        // Optional later: needs `composer require league/flysystem-aws-s3-v3`, then UPLOAD_DISK=r2.
        'r2' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],

    'links' => [public_path('storage') => storage_path('app/public')],
];
