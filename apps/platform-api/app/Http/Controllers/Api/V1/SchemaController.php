<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AppDefinitionService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchemaController extends Controller
{
    public function __construct(private readonly AppDefinitionService $definitions) {}

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $head = $this->definitions->head($project);

        return response()
            ->json(['data' => $head], 200, [], DocumentCodec::JSON_FLAGS & ~JSON_THROW_ON_ERROR)
            ->setEtag((string) $head['revision'])
            ->header('Cache-Control', 'private, no-cache');
    }

    /** Replace the whole document (If-Match: <revision> required). */
    public function replace(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $base = $this->ifMatchRevision($request);
        $payload = json_decode($request->getContent(), true, 128);
        if (! is_array($payload) || ! is_array($payload['document'] ?? null)) {
            throw ApiException::unprocessable('invalid_body', 'body must be {"document": {...}, "message"?: string}');
        }
        $message = isset($payload['message']) && is_string($payload['message']) ? mb_substr($payload['message'], 0, 200) : null;
        $result = $this->definitions->replace($project, $base, $payload['document'], $request->user(), $message);

        return response()->json(['data' => $result])->setEtag((string) $result['revision']);
    }

    /** Apply a batch of typed commands against baseRevision. */
    public function commands(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        try {
            $body = json_decode($request->getContent(), false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::unprocessable('invalid_json', 'request body is not valid JSON');
        }
        if (! $body instanceof \stdClass || ! isset($body->baseRevision) || ! is_int($body->baseRevision) || ! isset($body->commands) || ! is_array($body->commands)) {
            throw ApiException::unprocessable('invalid_body', 'body must be {"baseRevision": int, "commands": [...], "message"?: string}');
        }
        $message = isset($body->message) && is_string($body->message) ? mb_substr($body->message, 0, 200) : null;
        $result = $this->definitions->applyCommands($project, $body->baseRevision, array_values($body->commands), $request->user(), $message);

        return response()->json(['data' => $result])->setEtag((string) $result['revision']);
    }

    private function ifMatchRevision(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'), ' "W/');
        if ($header === '' || ! ctype_digit($header)) {
            throw new ApiException(428, 'precondition_required', 'If-Match header with the current revision is required');
        }

        return (int) $header;
    }
}
