<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Data\Actor;
use App\Domain\Data\DataContext;
use App\Domain\Data\RecordService;
use App\Http\Controllers\Controller;
use App\Models\AppApiKey;
use App\Models\Project;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M19: allowlisted REST endpoints generated from data models. Payloads use field *names*.
 * Access = API key scopes ∩ the app permission matrix for the key's role.
 */
class AppApiController extends Controller
{
    public function __construct(private readonly RecordService $records) {}

    private function setup(Request $request, Project $project, string $modelName, string $scope): array
    {
        /** @var AppApiKey $key */
        $key = $request->attributes->get('appApiKey');
        if (! in_array($scope, $key->scopes, true)) {
            throw new ApiException(403, 'insufficient_scope', "API key lacks scope {$scope}");
        }
        $ctx = DataContext::forProject($project);

        return [$ctx, $ctx->model($modelName), Actor::apiKey($key->id, $key->role, $request->ip()), $key];
    }

    /** Translate {name: value} to {fieldId: value}; unknown names are rejected. */
    private function byId(array $model, array $fields): array
    {
        $map = array_column($model['fields'], 'id', 'name');
        $out = [];
        $unknown = [];
        foreach ($fields as $name => $value) {
            if (! isset($map[$name])) {
                $unknown[(string) $name] = 'unknown field';

                continue;
            }
            $out[$map[$name]] = $value;
        }
        if ($unknown !== []) {
            throw ApiException::unprocessable('record_invalid', 'ข้อมูลไม่ถูกต้อง', ['fields' => $unknown]);
        }

        return $out;
    }

    private function presentErrors(array $model, ApiException $e): ApiException
    {
        if ($e->errorCode !== 'record_invalid') {
            return $e;
        }
        $names = array_column($model['fields'], 'name', 'id');
        $fields = [];
        foreach ($e->details['fields'] ?? [] as $id => $msg) {
            $fields[$names[$id] ?? $id] = $msg;
        }

        return ApiException::unprocessable('record_invalid', $e->getMessage(), ['fields' => $fields]);
    }

    public function index(Request $request, Project $project, string $model): JsonResponse
    {
        [$ctx, $m, $actor, $key] = $this->setup($request, $project, $model, 'records:read');
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:64'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $map = array_column($m['fields'], 'id', 'name');
        $sort = isset($q['sort']) ? ($map[$q['sort']] ?? $q['sort']) : null;
        $page = $this->records->list($ctx, $m, $actor, ['search' => $q['search'] ?? null, 'sort' => $sort, 'dir' => $q['dir'] ?? null, 'perPage' => $q['per_page'] ?? 20], $key->environment);

        return response()->json([
            'data' => array_map(fn ($r) => RecordService::present($r, $m, true), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Project $project, string $model, string $id): JsonResponse
    {
        [$ctx, $m, $actor, $key] = $this->setup($request, $project, $model, 'records:read');
        $ctx->assertAllowed($m, $actor, 'read');

        return response()->json(['data' => RecordService::present($this->records->find($ctx, $m, $id, $key->environment), $m, true)]);
    }

    public function store(Request $request, Project $project, string $model): JsonResponse
    {
        [$ctx, $m, $actor, $key] = $this->setup($request, $project, $model, 'records:write');
        $body = $request->validate(['fields' => ['required', 'array']]);
        try {
            $record = $this->records->create($ctx, $m, $this->byId($m, $body['fields']), $actor, 'api', $key->environment);
        } catch (ApiException $e) {
            throw $this->presentErrors($m, $e);
        }

        return response()->json(['data' => RecordService::present($record->fresh(), $m, true)], 201);
    }

    public function update(Request $request, Project $project, string $model, string $id): JsonResponse
    {
        [$ctx, $m, $actor, $key] = $this->setup($request, $project, $model, 'records:write');
        $body = $request->validate(['fields' => ['required', 'array'], 'version' => ['nullable', 'integer', 'min:1']]);
        $record = $this->records->find($ctx, $m, $id, $key->environment);
        try {
            $record = $this->records->update($ctx, $m, $record, $this->byId($m, $body['fields']), $body['version'] ?? null, $actor, 'api');
        } catch (ApiException $e) {
            throw $this->presentErrors($m, $e);
        }

        return response()->json(['data' => RecordService::present($record->fresh(), $m, true)]);
    }

    public function destroy(Request $request, Project $project, string $model, string $id): JsonResponse
    {
        [$ctx, $m, $actor, $key] = $this->setup($request, $project, $model, 'records:write');
        $this->records->delete($ctx, $m, $this->records->find($ctx, $m, $id, $key->environment), $actor);

        return response()->json(['data' => ['ok' => true]]);
    }
}
