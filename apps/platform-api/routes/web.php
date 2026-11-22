<?php

use App\Http\Controllers\Api\V1\Integrations\GoogleConnectionController;
use App\Http\Controllers\RuntimeController;
use Illuminate\Support\Facades\Route;

// Google OAuth redirect target. Registered as <studio origin>/api/v1/integrations/google/callback;
// uses the web (session) middleware because it is a top-level navigation coming back from Google.
Route::get('/api/v1/integrations/google/callback', [GoogleConnectionController::class, 'callback'])
    ->middleware('throttle:20,1');

// Platform hosts answer with a service description; any other Host is a published site.
Route::get('/_assets/{asset}', [RuntimeController::class, 'asset'])->where('asset', '[0-9a-zA-Z]{26}');
Route::get('/{path?}', [RuntimeController::class, 'page'])->where('path', '^(?!api/|up$|sanctum/|runtime/).*$');
