<?php

return [
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/zip',
        'video/mp4',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],

    'max_size_bytes' => (int) env('UPLOADS_MAX_SIZE_BYTES', 5368709120),
    'multipart_threshold_bytes' => (int) env('UPLOADS_MULTIPART_THRESHOLD_BYTES', 104857600),
    'part_size_bytes' => (int) env('UPLOADS_PART_SIZE_BYTES', 16777216),
    'default_quota_bytes' => (int) env('UPLOADS_DEFAULT_QUOTA_BYTES', 10737418240),

    'ttl' => [
        'single_put' => (int) env('UPLOADS_TTL_SINGLE_PUT', 900),
        'multipart' => (int) env('UPLOADS_TTL_MULTIPART', 3600),
        'download' => (int) env('UPLOADS_TTL_DOWNLOAD', 300),
    ],

    'rate_limits' => [
        'initiate' => (int) env('UPLOADS_RATE_LIMIT_INITIATE', 60),
        'default' => (int) env('UPLOADS_RATE_LIMIT_DEFAULT', 300),
    ],

    'idempotency_ttl' => (int) env('UPLOADS_IDEMPOTENCY_TTL', 86400),
    'cleanup_grace_seconds' => (int) env('UPLOADS_CLEANUP_GRACE_SECONDS', 900),

    // Guzzle defaults to no timeout at all; a hung S3/SQS call would stall the request or worker.
    // Keep the request timeout above the SQS long-poll wait (20 s).
    'aws_http' => [
        'connect_timeout' => (float) env('AWS_HTTP_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('AWS_HTTP_TIMEOUT', 30),
    ],

    'key_prefix' => env('UPLOADS_KEY_PREFIX', 'users'),
    'bucket' => env('AWS_BUCKET'),
    's3_events_queue_url' => env('S3_EVENTS_QUEUE_URL'),
];
