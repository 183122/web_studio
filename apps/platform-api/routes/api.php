<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RevisionController;
use App\Http\Controllers\Api\V1\SchemaController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('health');

Route::prefix('v1')->group(function () {
    // ---- Authentication (M01) ----
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password');
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:6,1')->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');
        });
    });

    // Signed, short-lived asset URLs (no session needed; signature is the capability).
    Route::get('assets/{asset}/content', [AssetController::class, 'content'])
        ->middleware('signed:relative')->name('assets.content');

    Route::get('templates', [TemplateController::class, 'index']);
    Route::get('templates/{slug}', [TemplateController::class, 'show']);

    Route::middleware(['auth:sanctum', 'throttle:api', Idempotency::class])->group(function () {
        // ---- Workspaces (M02) ----
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::get('workspaces/{workspace}/members', [WorkspaceController::class, 'members']);
        Route::get('workspaces/{workspace}/audit-logs', [WorkspaceController::class, 'auditLogs']);

        // ---- Projects (M06) ----
        Route::get('workspaces/{workspace}/projects', [ProjectController::class, 'index']);
        Route::post('workspaces/{workspace}/projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::patch('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
        Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->withTrashed();
        Route::post('projects/{project}/archive', [ProjectController::class, 'archive']);
        Route::post('projects/{project}/unarchive', [ProjectController::class, 'unarchive']);
        Route::post('projects/{project}/duplicate', [ProjectController::class, 'duplicate']);
        Route::put('projects/{project}/favorite', [ProjectController::class, 'favorite']);
        Route::delete('projects/{project}/favorite', [ProjectController::class, 'unfavorite']);

        // ---- Canonical schema (§6) ----
        Route::get('projects/{project}/schema', [SchemaController::class, 'show']);
        Route::put('projects/{project}/schema', [SchemaController::class, 'replace']);
        Route::post('projects/{project}/commands', [SchemaController::class, 'commands'])->middleware('throttle:commands');
        Route::get('projects/{project}/revisions', [RevisionController::class, 'index']);
        Route::post('projects/{project}/revisions', [RevisionController::class, 'store']);
        Route::get('projects/{project}/revisions/{revision}', [RevisionController::class, 'show'])->whereNumber('revision');
        Route::post('projects/{project}/revisions/{revision}/restore', [RevisionController::class, 'restore'])->whereNumber('revision');

        // ---- Assets (M11) ----
        Route::get('workspaces/{workspace}/assets', [AssetController::class, 'index']);
        Route::post('workspaces/{workspace}/assets', [AssetController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy']);
    });
});
