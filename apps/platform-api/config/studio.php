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

    'ai' => [
        // Platform credential (optional). Organisations can bring their own key (BYOK).
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        'anthropic_base_url' => env('ANTHROPIC_BASE_URL'),
        'model' => env('STUDIO_AI_MODEL', 'claude-opus-5-5'),
        'effort' => env('STUDIO_AI_EFFORT', 'medium'),
        'max_tokens' => (int) env('STUDIO_AI_MAX_TOKENS', 16000),
        'timeout_seconds' => (float) env('STUDIO_AI_TIMEOUT', 240),
        'max_retries' => 2,
        // Server-side refusal fallback (beta) for Claude Opus 5.5.
        'fallbacks' => env('STUDIO_AI_FALLBACKS', 'default'),
        'repair_attempts' => 2,
        // 1 credit = USD 0.01 of provider cost (rounded up). Prices per million tokens.
        'credit_usd' => 0.01,
        'pricing' => [
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        ],
        'max_prompt_chars' => 4000,
        'proposal_ttl_hours' => 24,
    ],

    // Sign-ups per IP per hour (abuse protection). Raised only for automated E2E runs.
    'register_per_hour' => (int) env('STUDIO_REGISTER_PER_HOUR', 10),

    // Default plan code assigned to new organizations (see PlanSeeder).
    'default_plan' => env('STUDIO_DEFAULT_PLAN', 'free'),
];
