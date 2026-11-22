<?php

namespace App\Services;

use App\Domain\Schema\DefinitionFactory;
use App\Domain\Schema\DocumentCodec;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectService
{
    public function __construct(
        private readonly AppDefinitionService $definitions,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Workspace $workspace, User $user, string $name, string $description = '', ?Template $template = null): Project
    {
        $project = DB::transaction(function () use ($workspace, $user, $name, $description, $template) {
            $org = Organization::query()->whereKey($workspace->organization_id)->lockForUpdate()->firstOrFail();
            $this->entitlements->assertCanCreateProject($org);

            $project = Project::create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $workspace->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($workspace, $name),
                'description' => $description,
                'status' => 'active',
                'template_id' => $template?->id,
                'created_by' => $user->id,
            ]);

            if ($template !== null) {
                $version = $template->currentVersion;
                if ($version === null) {
                    throw ApiException::unprocessable('template_unavailable', 'เทมเพลตนี้ยังไม่พร้อมใช้งาน');
                }
                $doc = DefinitionFactory::fromTemplate(DocumentCodec::decode($version->document), $project->id, $name);
                $this->definitions->initialize($project, $doc, 'template', $user, "สร้างจากเทมเพลต {$template->name}");
            } else {
                $this->definitions->initialize($project, DefinitionFactory::blank($project->id, $name), 'blank', $user, 'สร้างโปรเจกต์');
            }

            return $project;
        });

        $this->audit->record('project.created', $project, ['template' => $template?->slug]);

        return $project;
    }

    public function duplicate(Project $source, User $user, ?string $name = null): Project
    {
        $name ??= Str::limit($source->name, 100, '').' (สำเนา)';
        $head = $this->definitions->head($source);

        $project = DB::transaction(function () use ($source, $user, $name, $head) {
            $org = Organization::query()->whereKey($source->organization_id)->lockForUpdate()->firstOrFail();
            $this->entitlements->assertCanCreateProject($org);
            $workspace = $source->workspace;
            $project = Project::create([
                'organization_id' => $source->organization_id,
                'workspace_id' => $source->workspace_id,
                'name' => $name,
                'slug' => $this->uniqueSlug($workspace, $name),
                'description' => $source->description,
                'status' => 'active',
                'template_id' => $source->template_id,
                'created_by' => $user->id,
            ]);
            $doc = DefinitionFactory::fromTemplate($head['document'], $project->id, $name);
            $this->definitions->initialize($project, $doc, 'clone', $user, "คัดลอกจาก {$source->name} (rev {$head['revision']})");

            return $project;
        });
        $this->audit->record('project.cloned', $project, ['source_project_id' => $source->id]);

        return $project;
    }

    public function restoreFromTrash(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $org = Organization::query()->whereKey($project->organization_id)->lockForUpdate()->firstOrFail();
            $this->entitlements->assertCanCreateProject($org);
            $project->restore();
        });
        $this->audit->record('project.restored', $project);
    }

    private function uniqueSlug(Workspace $workspace, string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'project';
        $slug = $base;
        while (Project::withTrashed()->where('workspace_id', $workspace->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
