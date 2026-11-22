<?php

namespace App\Domain\Publishing;

use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\Upcaster;
use App\Jobs\PublishRelease;
use App\Models\AppDefinition;
use App\Models\Asset;
use App\Models\Deployment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UsageMeter;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publish pipeline (spec §8): freeze → validate → security scan → manifest → stage → smoke test →
 * atomic switch → health check. Any failure before the switch leaves the previous release live;
 * a failed health check switches back.
 */
class PublishService
{
    public function __construct(
        private readonly DomainService $domains,
        private readonly SiteResolver $sites,
        private readonly AuditLogger $audit,
    ) {}

    public function requestPublish(Project $project, User $user): Deployment
    {
        if ($project->status !== 'active') {
            throw ApiException::unprocessable('project_archived', 'โปรเจกต์ที่เก็บถาวรเผยแพร่ไม่ได้');
        }
        if (config('studio.publishing.require_verified_email') && ! $user->hasVerifiedEmail()) {
            throw ApiException::unprocessable('email_unverified', 'กรุณายืนยันอีเมลก่อนเผยแพร่เว็บไซต์');
        }
        $org = Organization::query()->with('plan')->findOrFail($project->organization_id);
        if ($org->isSuspended()) {
            throw ApiException::conflict('organization_suspended', 'องค์กรนี้ถูกระงับการใช้งาน จึงเผยแพร่ไม่ได้');
        }
        $build = app(UsageMeter::class)->status($org, 'build_seconds');
        if ($build['used'] >= $build['limit']) {
            throw ApiException::quota('นาทีการเผยแพร่ต่อเดือน', $build['limit']);
        }
        if ($this->domains->subdomainOf($project) === null) {
            throw ApiException::unprocessable('subdomain_required', 'กรุณาตั้งชื่อโดเมนย่อยก่อนเผยแพร่');
        }

        $deployment = DB::transaction(function () use ($project, $user) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (Deployment::query()->where('project_id', $project->id)->whereIn('status', ['queued', 'running'])->exists()) {
                throw ApiException::conflict('deploy_in_progress', 'กำลังเผยแพร่อยู่ กรุณารอให้เสร็จก่อน');
            }
            $head = AppDefinition::query()->where('project_id', $project->id)->firstOrFail();
            // Freeze: the release stores the exact saved revision, independent of later edits.
            $release = Release::create([
                'project_id' => $project->id,
                'version' => (int) Release::query()->where('project_id', $project->id)->max('version') + 1,
                'source_revision' => $head->current_revision,
                'document' => $head->document,
                'content_hash' => $head->content_hash,
                'manifest' => [],
                'status' => 'building',
                'created_by' => $user->id,
            ]);

            return Deployment::create([
                'project_id' => $project->id,
                'release_id' => $release->id,
                'previous_release_id' => $locked->live_release_id,
                'action' => 'publish',
                'status' => 'queued',
                'source_revision' => $head->current_revision,
                'steps' => [['step' => 'freeze', 'status' => 'ok', 'message' => "ตรึงเวอร์ชัน rev {$head->current_revision} (v{$release->version})", 'at' => now()->toIso8601String()]],
                'created_by' => $user->id,
            ]);
        });
        $this->audit->record('deploy.requested', $project, ['deployment_id' => $deployment->id]);
        PublishRelease::dispatch($deployment->id);

        return $deployment;
    }

    public function run(Deployment $deployment): void
    {
        if ($deployment->status !== 'queued') {
            return;
        }
        $deployment->update(['status' => 'running', 'started_at' => now()]);
        $startedAt = microtime(true);
        $project = Project::query()->findOrFail($deployment->project_id);
        $release = Release::query()->findOrFail($deployment->release_id);
        $switched = false;

        try {
            // 1. Validate the frozen document with the canonical validator.
            $doc = Upcaster::upcast(DocumentCodec::decode($release->document));
            $issues = DefinitionValidator::validate($doc);
            if ($issues !== []) {
                throw new PublishFailure('validation_failed', 'เอกสารไม่ผ่านการตรวจสอบ: '.$issues[0]['message']);
            }
            $deployment->log('validate', 'ok', 'โครงสร้างและเนื้อหาผ่านการตรวจสอบ');

            // 2. Security scan: referenced assets exist, belong to the workspace and are safe to serve.
            $warnings = [];
            $assets = Asset::query()->where('workspace_id', $project->workspace_id)->whereIn('id', $doc['assets'])->get()->keyBy('id');
            foreach ($doc['assets'] as $id) {
                $asset = $assets[$id] ?? null;
                if ($asset === null || ! $asset->isServable()) {
                    throw new PublishFailure('asset_unavailable', "รูปภาพที่ใช้ในหน้าเว็บถูกลบหรือไม่ผ่านการสแกน ({$id}) — เปลี่ยนรูปหรือนำออกแล้วลองใหม่");
                }
                if ($asset->scan_status === 'not_scanned') {
                    $warnings[] = "รูป {$asset->original_name} ยังไม่ได้สแกนมัลแวร์ (ยังไม่ได้ตั้งค่าเครื่องสแกน)";
                }
            }
            $paths = array_column($doc['pages'], 'path');
            $redirectFrom = array_column($doc['routes'], 'from');
            $modelLabel = array_column($doc['dataModels'], 'label', 'id');
            $rules = array_column($doc['permissions'], 'rules', 'modelId');
            foreach ($doc['components'] as $node) {
                foreach ([$node['props']['href'] ?? null, ...array_column(is_array($node['props']['links'] ?? null) ? $node['props']['links'] : [], 'href')] as $href) {
                    if (is_string($href) && str_starts_with($href, '/')) {
                        $path = explode('#', explode('?', $href)[0])[0] ?: '/';
                        if (! in_array($path, $paths, true) && ! in_array($path, $redirectFrom, true)) {
                            $warnings[] = "ลิงก์ {$href} ชี้ไปยังหน้าที่ไม่มีอยู่";
                        }
                    }
                }
                $mid = $node['props']['modelId'] ?? '';
                if ($node['type'] === 'Form' && $mid !== '' && ! in_array('create', $rules[$mid]['public'] ?? [], true)) {
                    $warnings[] = "ฟอร์มของตาราง {$modelLabel[$mid]} ใช้ได้เฉพาะผู้ใช้ที่เข้าสู่ระบบ (ผู้เยี่ยมชมไม่มีสิทธิ์สร้าง)";
                }
                if ($node['type'] === 'DataTable' && $mid !== '' && in_array('read', $rules[$mid]['public'] ?? [], true)) {
                    $warnings[] = "ข้อมูลในตาราง {$modelLabel[$mid]} เปิดให้ทุกคนอ่านได้";
                }
            }
            $warnings = array_values(array_unique($warnings));
            $deployment->log('security_scan', 'ok', $warnings === [] ? 'ไม่พบปัญหา' : count($warnings).' คำเตือน');

            // 3. Manifest (immutable)
            $routes = array_map(fn ($p) => ['path' => $p['path'], 'pageId' => $p['id'], 'title' => $p['seo']['title'], 'noindex' => $p['seo']['noindex']], $doc['pages']);
            $release->manifest = [
                'routes' => $routes,
                'redirects' => $doc['routes'],
                'assets' => $doc['assets'],
                'contentHash' => hash('sha256', $release->document),
                'sourceRevision' => $release->source_revision,
                'warnings' => $warnings,
            ];
            $deployment->log('manifest', 'ok', count($routes).' หน้า, '.count($doc['assets']).' ไฟล์');

            // 4. Stage
            $release->status = 'ready';
            $release->save();
            $deployment->log('stage', 'ok', "release v{$release->version} พร้อมใช้งาน (ยังไม่เปิดสู่สาธารณะ)");

            // 5. Smoke test the staged artefact as the runtime will read it.
            $staged = Release::query()->findOrFail($release->id);
            if (hash('sha256', $staged->document) !== $staged->manifest['contentHash']) {
                throw new PublishFailure('smoke_failed', 'ข้อมูล release ไม่ตรงกับ manifest');
            }
            $sdoc = DocumentCodec::decode($staged->document);
            foreach ($staged->manifest['routes'] as $r) {
                $page = collect($sdoc['pages'])->firstWhere('id', $r['pageId']);
                if ($page === null || ! isset($sdoc['components'][$page['rootId']])) {
                    throw new PublishFailure('smoke_failed', "หน้า {$r['path']} แสดงผลไม่ได้");
                }
            }
            if (! RuntimeAssets::entry()) {
                $deployment->log('smoke_test', 'warning', 'ยังไม่ได้ build runtime bundle (npm run build -w apps/runtime-web)');
            } else {
                $deployment->log('smoke_test', 'ok', 'ทุกหน้าโหลดได้');
            }

            // 6. Atomic switch
            DB::transaction(function () use ($project, $deployment, $release) {
                $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                if ($locked->live_release_id !== $deployment->previous_release_id) {
                    throw new PublishFailure('concurrent_change', 'มีการเปลี่ยน release ระหว่างเผยแพร่ กรุณาลองใหม่');
                }
                $locked->forceFill(['live_release_id' => $release->id, 'published_at' => now()])->save();
            });
            $switched = true;
            $deployment->log('switch', 'ok', "สลับเว็บไซต์จริงไปที่ v{$release->version}");

            // 7. Health check through the same resolver the runtime uses.
            $host = $this->domains->subdomainOf($project)?->hostname;
            $resolved = $host ? $this->sites->resolve($host) : null;
            if ($resolved === null || $resolved['release']->id !== $release->id) {
                throw new PublishFailure('health_check_failed', 'ตรวจสอบเว็บไซต์หลังเผยแพร่ไม่ผ่าน');
            }
            $deployment->log('health_check', 'ok', "https://{$host} ใช้ release v{$release->version}");

            $deployment->update(['status' => 'succeeded', 'finished_at' => now()]);
            $this->audit->record('deploy.succeeded', $project, ['release' => $release->version, 'revision' => $release->source_revision], null, null, $deployment->created_by);
        } catch (Throwable $e) {
            $code = $e instanceof PublishFailure ? $e->errorCode : 'internal_error';
            $message = $e instanceof PublishFailure ? $e->getMessage() : 'เกิดข้อผิดพลาดภายในระหว่างเผยแพร่';
            if (! $e instanceof PublishFailure) {
                report($e);
            }
            if ($switched) {
                Project::query()->whereKey($project->id)->update(['live_release_id' => $deployment->previous_release_id]);
                $deployment->log('rollback', 'ok', 'คืนเว็บไซต์เป็น release ก่อนหน้า');
            }
            $release->update(['status' => 'failed']);
            $deployment->log($code, 'failed', $message);
            $deployment->update(['status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'finished_at' => now()]);
            $this->audit->record('deploy.failed', $project, ['error' => $code], null, null, $deployment->created_by);
        }
        app(UsageMeter::class)->add($project->organization_id, 'build_seconds', max(1, (int) ceil(microtime(true) - $startedAt)));
    }

    public function rollback(Project $project, Release $release, User $user): Deployment
    {
        if ($release->project_id !== $project->id || $release->status !== 'ready') {
            throw ApiException::unprocessable('release_unavailable', 'release นี้ใช้ย้อนกลับไม่ได้');
        }

        return DB::transaction(function () use ($project, $release, $user) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->live_release_id === $release->id) {
                throw ApiException::conflict('already_live', 'release นี้เป็นเวอร์ชันที่ใช้งานอยู่แล้ว');
            }
            $d = Deployment::create([
                'project_id' => $project->id, 'release_id' => $release->id, 'previous_release_id' => $locked->live_release_id,
                'action' => 'rollback', 'status' => 'succeeded', 'source_revision' => $release->source_revision,
                'steps' => [['step' => 'switch', 'status' => 'ok', 'message' => "ย้อนกลับเป็น v{$release->version}", 'at' => now()->toIso8601String()]],
                'created_by' => $user->id, 'started_at' => now(), 'finished_at' => now(),
            ]);
            $locked->forceFill(['live_release_id' => $release->id, 'published_at' => now()])->save();
            $this->audit->record('deploy.rollback', $project, ['release' => $release->version]);

            return $d;
        });
    }

    public function unpublish(Project $project, User $user): Deployment
    {
        return DB::transaction(function () use ($project, $user) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($locked->live_release_id === null) {
                throw ApiException::conflict('not_published', 'เว็บไซต์ยังไม่ได้เผยแพร่');
            }
            $d = Deployment::create([
                'project_id' => $project->id, 'release_id' => null, 'previous_release_id' => $locked->live_release_id,
                'action' => 'unpublish', 'status' => 'succeeded',
                'steps' => [['step' => 'switch', 'status' => 'ok', 'message' => 'หยุดเผยแพร่เว็บไซต์', 'at' => now()->toIso8601String()]],
                'created_by' => $user->id, 'started_at' => now(), 'finished_at' => now(),
            ]);
            $locked->forceFill(['live_release_id' => null])->save();
            $this->audit->record('deploy.unpublished', $project);

            return $d;
        });
    }
}
