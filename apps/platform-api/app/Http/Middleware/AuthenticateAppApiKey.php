<?php

namespace App\Http\Middleware;

use App\Models\AppApiKey;
use App\Models\Project;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generated-app REST API authentication: `Authorization: Bearer sk_<prefix>_<secret>`.
 * Keys are stored as SHA-256 hashes; the key must belong to the project in the URL.
 */
class AuthenticateAppApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if (preg_match('/^sk_([a-z0-9]{12})_([A-Za-z0-9]{40})$/', $token, $m) !== 1) {
            throw new ApiException(401, 'invalid_api_key', 'API key is missing or malformed');
        }
        $key = AppApiKey::query()->where('prefix', $m[1])->whereNull('revoked_at')->first();
        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $token))) {
            throw new ApiException(401, 'invalid_api_key', 'API key is invalid or revoked');
        }
        $project = $request->route('project');
        $projectId = $project instanceof Project ? $project->id : (string) $project;
        if ($key->project_id !== $projectId) {
            throw new ApiException(404, 'not_found', 'ไม่พบข้อมูลที่ต้องการ');
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('appApiKey', $key);

        return $next($request);
    }
}
