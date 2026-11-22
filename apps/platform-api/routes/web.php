<?php

use App\Http\Controllers\RuntimeController;
use Illuminate\Support\Facades\Route;

// Platform hosts answer with a service description; any other Host is a published site.
Route::get('/_assets/{asset}', [RuntimeController::class, 'asset'])->where('asset', '[0-9a-zA-Z]{26}');
Route::get('/{path?}', [RuntimeController::class, 'page'])->where('path', '^(?!api/|up$|sanctum/|runtime/).*$');
