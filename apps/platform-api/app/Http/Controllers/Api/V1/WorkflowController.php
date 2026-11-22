<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Data\DataContext;
use App\Domain\Data\RecordInput;
use App\Domain\Data\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkflowRun;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function runs(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $runs = WorkflowRun::query()->where('project_id', $project->id)
            ->when($request->query('workflowId'), fn ($q, $id) => $q->where('workflow_id', $id))
            ->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($runs->items())->map(fn (WorkflowRun $r) => [
                'id' => $r->id, 'workflowId' => $r->workflow_id, 'recordId' => $r->record_id, 'event' => $r->event,
                'status' => $r->status, 'steps' => $r->steps, 'error' => $r->error, 'createdAt' => $r->created_at?->toIso8601String(),
            ]),
            'meta' => ['currentPage' => $runs->currentPage(), 'lastPage' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    /** Evaluate a saved workflow against sample data. No records are written and no e-mail is sent. */
    public function dryRun(Request $request, Project $project, string $workflowId, WorkflowEngine $engine): JsonResponse
    {
        $this->authorize('view', $project);
        $data = $request->validate(['data' => ['present', 'array']]);
        $ctx = DataContext::forProject($project);
        $workflow = collect($ctx->doc['workflows'])->firstWhere('id', $workflowId);
        if ($workflow === null) {
            throw new ApiException(404, 'not_found', 'ไม่พบ workflow');
        }
        $model = $ctx->model($workflow['trigger']['modelId']);
        $fields = DataContext::fields($model);
        $values = [];
        foreach ($fields as $id => $f) {
            $values[$id] = RecordInput::normalize($f, $data['data'][$id] ?? null);
        }
        $plan = $engine->plan($model, $workflow, $values);

        return response()->json(['data' => ['matched' => $plan['matched'], 'steps' => $plan['steps'], 'actions' => $plan['actions'], 'dryRun' => true]]);
    }
}
