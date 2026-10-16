<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'name' => 'SSShare',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
    'key' => (string) Env::get('APP_KEY', ''),

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'ssshare'),
        'username' => Env::get('DB_USERNAME', 'ssshare'),
        'password' => Env::get('DB_PASSWORD', ''),
    ],

    'storage' => [
        'driver' => Env::get('STORAGE_DRIVER', 'local'),
        'local_path' => BASE_PATH . '/storage/private',
        'tmp_path' => BASE_PATH . '/storage/tmp',
        's3' => [
            'endpoint' => Env::get('S3_ENDPOINT', 'https://s3.amazonaws.com'),
            'region' => Env::get('S3_REGION', 'us-east-1'),
            'bucket' => Env::get('S3_BUCKET', 'ssshare'),
            'key' => Env::get('S3_KEY', ''),
            'secret' => Env::get('S3_SECRET', ''),
            'path_style' => (bool) Env::get('S3_PATH_STYLE', true),
        ],
        // Workspace storage regions: region => bucket (on the same S3 endpoint).
        'regions' => [
            'eu' => Env::get('S3_BUCKET_EU'),
            'us' => Env::get('S3_BUCKET_US'),
        ],
    ],

    'mail' => [
        'transport' => Env::get('MAIL_TRANSPORT', 'log'),
        'host' => Env::get('MAIL_HOST', '127.0.0.1'),
        'port' => (int) Env::get('MAIL_PORT', 1025),
        'username' => Env::get('MAIL_USERNAME', ''),
        'password' => Env::get('MAIL_PASSWORD', ''),
        'encryption' => Env::get('MAIL_ENCRYPTION', ''),
        'from' => Env::get('MAIL_FROM', 'no-reply@ssshare.local'),
    ],

    'billing' => [
        'webhook_secret' => (string) Env::get('BILLING_WEBHOOK_SECRET', ''),
        // Hosted checkout of your payment provider. Empty: a local test checkout is used outside production.
        'checkout_url' => (string) Env::get('BILLING_CHECKOUT_URL', ''),
    ],

    // Allow webhooks to private/loopback addresses (development only).
    // Set to the header your CDN adds (e.g. CF-IPCountry) to record coarse viewer countries.
    'analytics' => ['country_header' => (string) Env::get('ANALYTICS_COUNTRY_HEADER', '')],
    'webhooks_allow_private' => (bool) Env::get('WEBHOOKS_ALLOW_PRIVATE', false),

    'ai' => [
        'api_key' => (string) Env::get('ANTHROPIC_API_KEY', ''),
        'model' => (string) Env::get('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],

    'uploads' => [
        'anonymous_enabled' => (bool) Env::get('ANONYMOUS_UPLOADS', true),
        'max_file_bytes' => 25 * 1024 * 1024,
        'max_pixels' => 50_000_000,
        'max_dimension' => 16_384,
        'max_text_bytes' => 512 * 1024,
        'max_text_lines' => 20_000,
        'max_video_bytes' => 200 * 1024 * 1024,
        'max_encrypted_bytes' => 100 * 1024 * 1024,
        'resumable_chunk_bytes' => 8 * 1024 * 1024,
        'default_user_quota_bytes' => 1024 * 1024 * 1024,
        // Uploads per hour per IP for guests, per account for members.
        'guest_hourly_limit' => 60,
        'member_hourly_limit' => 300,
    ],

    'expiration' => [
        // Seconds a burned/consumed item stays fetchable for the viewer who consumed it.
        'consume_grace_seconds' => 120,
        'unique_view_window_seconds' => 1800,
        'guest_max_seconds' => 30 * 86400,
        'member_max_seconds' => 365 * 86400,
    ],

    'security' => [
        'signed_url_ttl' => 300,
        'password_attempts_per_window' => 5,
        'password_window_seconds' => 900,
        'login_attempts_per_window' => 10,
        'clamav_host' => Env::get('CLAMAV_HOST', ''),
    ],

    'api' => [
        'requests_per_minute' => 120,
        'free_monthly_uploads' => 100,
    ],

    'admin' => [
        'email' => Env::get('ADMIN_EMAIL', ''),
        'password' => Env::get('ADMIN_PASSWORD', ''),
    ],
];
