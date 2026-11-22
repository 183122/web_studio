<?php

use Illuminate\Support\Facades\Route;

// The platform API is consumed by the Studio SPA (apps/studio-web). The root only identifies the service.
Route::get('/', fn () => response()->json([
    'service' => 'ai-studio-cloud/platform-api',
    'api' => '/api/v1',
    'health' => '/api/health',
]));
