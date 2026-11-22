<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\AiCredential;
use App\Models\AiProposal;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    public function __construct(private readonly AiService $ai) {}

    private static function presentRequest(AiRequest $r): array
    {
        return [
            'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'provider' => $r->provider, 'model' => $r->model,
            'fallbackUsed' => $r->fallback_used, 'result' => $r->status === 'succeeded' ? $r->result : null,
            'error' => $r->error_code ? ['code' => $r->error_code, 'message' => $r->error_message] : null,
            'usage' => ['inputTokens' => $r->input_tokens, 'outputTokens' => $r->output_tokens, 'credits' => $r->status === 'succeeded' || $r->status === 'failed' ? $r->credits_charged : $r->credits_reserved],
            'createdAt' => $r->created_at?->toIso8601String(),
        ];
    }

    private static function presentProposal(AiProposal $p): array
    {
        return [
            'id' => $p->id, 'status' => $p->status, 'baseRevision' => $p->base_revision, 'appliedRevision' => $p->applied_revision,
            'summary' => $p->summary, 'commands' => json_decode($p->commands, true), 'expiresAt' => $p->expires_at?->toIso8601String(),
        ];
    }

    public function status(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->ai->status($project)]);
    }

    public function plan(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['prompt' => ['required', 'string'], 'consent' => ['sometimes', 'boolean']]);
        $r = $this->ai->start($project, $request->user(), 'plan', $data['prompt'], (bool) ($data['consent'] ?? false));

        return response()->json(['data' => self::presentRequest($r->fresh())], 202);
    }

    public function patch(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'prompt' => ['required', 'string'],
            'consent' => ['sometimes', 'boolean'],
            'pageId' => ['nullable', 'string', 'max:64'],
            'componentId' => ['nullable', 'string', 'max:64'],
        ]);
        $r = $this->ai->start($project, $request->user(), 'patch', $data['prompt'], (bool) ($data['consent'] ?? false), array_filter([
            'pageId' => $data['pageId'] ?? null, 'componentId' => $data['componentId'] ?? null,
        ]));

        return response()->json(['data' => self::presentRequest($r->fresh())], 202);
    }

    private function findRequest(Project $project, string $id): AiRequest
    {
        return AiRequest::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบคำขอ AI'));
    }

    private function findProposal(Project $project, string $id): AiProposal
    {
        return AiProposal::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบข้อเสนอ'));
    }

    public function showRequest(Project $project, string $id): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => self::presentRequest($this->findRequest($project, $id))]);
    }

    public function generate(Project $project, string $id): JsonResponse
    {
        $this->authorize('update', $project);
        $proposal = $this->ai->generate($project, $this->findRequest($project, $id));

        return response()->json(['data' => self::presentProposal($proposal)], 201);
    }

    public function showProposal(Project $project, string $id): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => self::presentProposal($this->findProposal($project, $id))]);
    }

    public function apply(Request $request, Project $project, string $id): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['baseRevision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->ai->apply($project, $this->findProposal($project, $id), (int) $data['baseRevision'], $request->user())]);
    }

    public function reject(Project $project, string $id): JsonResponse
    {
        $this->authorize('update', $project);
        $p = $this->findProposal($project, $id);
        $this->ai->reject($project, $p);

        return response()->json(['data' => self::presentProposal($p->fresh())]);
    }

    /** BYOK: organisation-wide key, set by a workspace admin. The key is never returned. */
    public function setCredential(Request $request, Workspace $workspace, AuditLogger $audit): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $data = $request->validate(['provider' => ['required', Rule::in(['anthropic'])], 'apiKey' => ['required', 'string', 'min:20', 'max:300', 'regex:/^[A-Za-z0-9_\-]+$/']]);
        $cred = AiCredential::query()->updateOrCreate(
            ['organization_id' => $workspace->organization_id, 'provider' => $data['provider']],
            ['secret' => $data['apiKey'], 'last4' => substr($data['apiKey'], -4), 'created_by' => $request->user()->id],
        );
        $audit->record('ai.credential_set', $workspace, ['provider' => $data['provider'], 'last4' => $cred->last4]);

        return response()->json(['data' => ['provider' => $cred->provider, 'last4' => $cred->last4, 'updatedAt' => $cred->updated_at?->toIso8601String()]]);
    }

    public function deleteCredential(Workspace $workspace, string $provider, AuditLogger $audit): JsonResponse
    {
        $this->authorize('admin', $workspace);
        AiCredential::query()->where('organization_id', $workspace->organization_id)->where('provider', $provider)->delete();
        $audit->record('ai.credential_deleted', $workspace, ['provider' => $provider]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function showCredential(Workspace $workspace): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $cred = AiCredential::query()->where('organization_id', $workspace->organization_id)->get();

        return response()->json(['data' => $cred->map(fn ($c) => ['provider' => $c->provider, 'last4' => $c->last4, 'updatedAt' => $c->updated_at?->toIso8601String()])]);
    }
}
