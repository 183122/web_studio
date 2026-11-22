<?php

return [
    // Monorepo packages directory holding shared JSON Schemas, component registry and templates.
    'packages_path' => env('STUDIO_PACKAGES_PATH', base_path('../../packages')),

    // Public origin of the studio SPA (used for e-mail links).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    'schema' => [
        'max_document_bytes' => 2_000_000,
        'max_commands_per_request' => 200,
        // A new immutable checkpoint is written when the previous one is older than this.
        'checkpoint_interval_seconds' => (int) env('STUDIO_CHECKPOINT_INTERVAL', 300),
    ],

    'assets' => [
        'disk' => env('STUDIO_ASSET_DISK', 'assets'),
        'max_upload_bytes' => (int) env('STUDIO_ASSET_MAX_BYTES', 10 * 1024 * 1024),
        'max_pixels' => 16_000_000,
        'allowed_mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'signed_url_ttl_minutes' => 30,
        'clamav' => [
            'host' => env('CLAMAV_HOST'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => 10,
        ],
    ],

    // Default plan code assigned to new organizations (see PlanSeeder).
    'default_plan' => env('STUDIO_DEFAULT_PLAN', 'free'),
];
