<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppApiKey;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    private static function present(AppApiKey $k): array
    {
        return [
            'id' => $k->id, 'name' => $k->name, 'prefix' => $k->prefix, 'environment' => $k->environment,
            'role' => $k->role, 'scopes' => $k->scopes, 'lastUsedAt' => $k->last_used_at?->toIso8601String(),
            'revokedAt' => $k->revoked_at?->toIso8601String(), 'createdAt' => $k->created_at?->toIso8601String(),
        ];
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('manage', $project);

        return response()->json(['data' => AppApiKey::query()->where('project_id', $project->id)->orderByDesc('created_at')->get()->map(fn ($k) => self::present($k))]);
    }

    /** The plaintext key is returned exactly once. */
    public function store(Request $request, Project $project, AuditLogger $audit): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'role' => ['required', Rule::in(['public', 'member', 'admin'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(AppApiKey::SCOPES)],
        ]);
        $prefix = Str::lower(Str::random(12));
        $prefix = preg_replace('/[^a-z0-9]/', 'x', $prefix);
        $token = "sk_{$prefix}_".Str::random(40);
        $key = AppApiKey::create([
            'project_id' => $project->id,
            'environment' => 'preview', // production keys arrive with publishing (Phase 4)
            'name' => $data['name'],
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $token),
            'role' => $data['role'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'created_by' => $request->user()->id,
        ]);
        $audit->record('api_key.created', $project, ['key_id' => $key->id, 'role' => $key->role, 'scopes' => $key->scopes]);

        return response()->json(['data' => self::present($key) + ['token' => $token]], 201);
    }

    public function destroy(Project $project, string $keyId, AuditLogger $audit): JsonResponse
    {
        $this->authorize('manage', $project);
        $key = AppApiKey::query()->where('project_id', $project->id)->whereKey($keyId)->firstOrFail();
        $key->forceFill(['revoked_at' => now()])->save();
        $audit->record('api_key.revoked', $project, ['key_id' => $key->id]);

        return response()->json(['data' => self::present($key)]);
    }
}
