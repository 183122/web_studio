<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Export\ProjectExporter;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectExporter $exporter, AuditLogger $audit): BinaryFileResponse
    {
        $this->authorize('manage', $project);
        $d = $request->validate(['source' => ['nullable', Rule::in(['draft', 'live'])], 'data' => ['nullable', Rule::in(['none', 'preview', 'production'])]]);
        $source = $d['source'] ?? 'draft';
        $data = ($d['data'] ?? 'none') === 'none' ? null : $d['data'];
        $path = $exporter->export($project, $source, $data);
        $audit->record('project.exported', $project, ['source' => $source, 'data' => $data ?? 'none']);

        return response()->download($path, $project->slug.'-export-'.now()->format('Ymd').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
