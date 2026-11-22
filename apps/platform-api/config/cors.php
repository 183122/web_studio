<?php

// Only the public per-app REST API (API-key authenticated, no cookies) is callable cross-origin.
// The Studio API is same-origin (Sanctum SPA cookies) and the published-site runtime API is served on
// each site's own host, so neither needs CORS.
return [
    'paths' => ['api/v1/apps/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
