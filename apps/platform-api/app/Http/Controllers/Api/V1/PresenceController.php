<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppDefinition;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Collaboration presence (who is in the editor, on which page, with what selected) plus the current
 * head revision so clients can pull remote changes. Ephemeral (cache, 15 s TTL); no content stored.
 */
class PresenceController extends Controller
{
    private const TTL = 15;

    public const COLORS = ['#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

    public function heartbeat(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $d = $request->validate([
            'pageId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
            'selectedId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
        ]);
        $user = $request->user();
        $key = "presence:{$project->id}";
        $now = now()->timestamp;
        $entries = Cache::lock("{$key}:lock", 5)->block(3, function () use ($key, $user, $d, $now, $project) {
            $entries = array_filter((array) Cache::get($key, []), fn ($e) => $now - $e['at'] < self::TTL);
            $entries[$user->id] = [
                'userId' => $user->id, 'name' => $user->name, 'color' => self::COLORS[hexdec(substr(md5($user->id), 0, 4)) % count(self::COLORS)],
                'pageId' => $d['pageId'] ?? null, 'selectedId' => $d['selectedId'] ?? null, 'at' => $now,
                'canEdit' => $user->can('update', $project),
            ];
            Cache::put($key, $entries, self::TTL * 4);

            return $entries;
        });

        return response()->json(['data' => [
            'headRevision' => (int) AppDefinition::query()->where('project_id', $project->id)->value('current_revision'),
            'participants' => array_values(array_map(fn ($e) => [
                'userId' => $e['userId'], 'name' => $e['name'], 'color' => $e['color'], 'pageId' => $e['pageId'], 'selectedId' => $e['selectedId'],
                'canEdit' => $e['canEdit'], 'me' => $e['userId'] === $user->id,
            ], $entries)),
        ]]);
    }

    public function leave(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $key = "presence:{$project->id}";
        Cache::lock("{$key}:lock", 5)->block(3, function () use ($key, $request) {
            $entries = (array) Cache::get($key, []);
            unset($entries[$request->user()->id]);
            Cache::put($key, $entries, self::TTL * 4);
        });

        return response()->json(['data' => ['ok' => true]]);
    }
}
