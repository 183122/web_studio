<?php

return [
    // Monorepo packages directory holding shared JSON Schemas, component registry and templates.
    // Monorepo: ../../packages. Deployment bundle (e.g. DirectAdmin): ./packages next to the app.
    'packages_path' => env('STUDIO_PACKAGES_PATH', is_dir(base_path('packages')) ? base_path('packages') : base_path('../../packages')),

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

    'publishing' => [
        // Published sites live at <slug>.<base_domain>. Wildcard DNS + TLS for it are configured once.
        'base_domain' => strtolower((string) env('PLATFORM_BASE_DOMAIN', 'sites.localhost')),
        // Target customers point their CNAME at (an edge that terminates TLS for verified hosts).
        'cname_target' => strtolower((string) env('PLATFORM_CNAME_TARGET', 'edge.sites.localhost')),
        // Hosts that serve the Studio/API, never a tenant site.
        'platform_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_HOSTS', 'localhost,127.0.0.1'))))),
        'reserved_slugs' => ['www', 'api', 'app', 'admin', 'studio', 'mail', 'smtp', 'ftp', 'cdn', 'static', 'assets', 'preview', 'edge', 'status', 'help', 'support', 'docs', 'blog', 'billing', 'auth', 'login', 'ns1', 'ns2', 'root', 'test', 'dev', 'staging'],
        'slug_cooldown_days' => (int) env('PLATFORM_SLUG_COOLDOWN_DAYS', 30),
        // DNS resolver for custom-domain checks: "system" (dns_get_record) or "static" (records from a JSON file, for local E2E).
        'dns_driver' => env('STUDIO_DNS_DRIVER', 'system'),
        'dns_static_file' => env('STUDIO_DNS_STATIC_FILE'),
        // How "certificate active" is determined: "probe" (TLS handshake to the host) or "assume" (local/dev).
        'tls_check' => env('STUDIO_TLS_CHECK', 'probe'),
        'require_verified_email' => (bool) env('STUDIO_PUBLISH_REQUIRE_VERIFIED_EMAIL', true),
        'preview_link_max_days' => 30,
    ],

    // Sign-ups per IP per hour (abuse protection). Raised only for automated E2E runs.
    // Outbound requests to user-supplied URLs (webhooks). Private networks only for local dev/E2E.
    'egress' => [
        'allow_private' => (bool) env('STUDIO_EGRESS_ALLOW_PRIVATE', false),
    ],

    // M03 billing. Only the `test` provider exists; it is refused when APP_ENV=production.
    // Activating a real provider requires explicit approval (spec §0) and its own adapter + secrets.
    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'test'),
        'webhook_secret' => env('BILLING_WEBHOOK_SECRET', env('APP_KEY')),
        'webhook_tolerance_seconds' => 300,
        'vat_rate_bp' => 700,
        'dunning_days' => [1, 3, 7],
        'free_plan' => 'free',
        'seller' => [
            'name' => env('BILLING_SELLER_NAME', 'AI Studio Cloud (ตัวอย่าง — ตั้งค่า BILLING_SELLER_*)'),
            'tax_id' => env('BILLING_SELLER_TAX_ID', ''),
            'address' => env('BILLING_SELLER_ADDRESS', ''),
        ],
    ],

    // PDPA: versions of the legal documents users consent to; retention periods (days).
    'legal' => [
        'terms_version' => env('LEGAL_TERMS_VERSION', '2026-11'),
        'privacy_version' => env('LEGAL_PRIVACY_VERSION', '2026-11'),
    ],
    'retention' => [
        'ai_requests_days' => (int) env('RETENTION_AI_REQUESTS_DAYS', 180),
        'webhook_deliveries_days' => (int) env('RETENTION_WEBHOOK_DELIVERIES_DAYS', 30),
        'sync_jobs_days' => (int) env('RETENTION_SYNC_JOBS_DAYS', 90),
        'app_audit_days' => (int) env('RETENTION_APP_AUDIT_DAYS', 365),
        'billing_events_days' => (int) env('RETENTION_BILLING_EVENTS_DAYS', 730),
        'deleted_accounts_grace_days' => (int) env('RETENTION_DELETED_ACCOUNT_GRACE_DAYS', 30),
    ],

    // Prometheus metrics endpoint (/api/internal/metrics); disabled when empty.
    'metrics_token' => (string) env('METRICS_TOKEN', ''),

    'register_per_hour' => (int) env('STUDIO_REGISTER_PER_HOUR', 10),

    // Default plan code assigned to new organizations (see PlanSeeder).
    'default_plan' => env('STUDIO_DEFAULT_PLAN', 'free'),
];
