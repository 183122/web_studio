<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function show(Request $request, Workspace $workspace, EntitlementService $entitlements): JsonResponse
    {
        $this->authorize('view', $workspace);
        $org = $workspace->organization()->with('plan')->firstOrFail();

        return response()->json(['data' => [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'role' => $request->user()->workspaceRole($workspace->id),
            'organization' => ['id' => $org->id, 'name' => $org->name, 'plan' => $org->plan?->only(['code', 'name'])],
            'usage' => $entitlements->usage($org),
        ]]);
    }

    public function members(Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json(['data' => $workspace->members()->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
        ])]);
    }

    /** Audit trail for workspace admins. */
    public function auditLogs(Workspace $workspace): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $logs = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'subjectType' => $l->subject_type,
                'subjectId' => $l->subject_id,
                'actor' => $l->actor?->only(['id', 'name']),
                'metadata' => $l->metadata,
                'createdAt' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['currentPage' => $logs->currentPage(), 'lastPage' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
