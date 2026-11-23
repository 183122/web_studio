<?php

namespace App\Domain\Marketplace;

use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\SemanticValidator;
use App\Domain\Schema\Upcaster;
use App\Jobs\SendBillingEmail;
use App\Models\AppDefinition;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateSubmission;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Community template marketplace (M12): sanitized snapshots → human moderation → versioned listing
 * with author + license → install copies files into the installer's workspace. Templates are data
 * (AppDefinition) only: no records, no secrets, never code.
 */
class MarketplaceService
{
    public const CATEGORIES = ['business', 'education', 'event', 'shop', 'portfolio', 'community', 'other'];

    public const LICENSES = ['free', 'personal'];

    public const MAX_ASSET_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly AuditLogger $audit, private readonly EntitlementService $entitlements) {}

    /** @return array{0: array, 1: array} sanitized document + report of what was removed */
    public static function sanitize(array $doc): array
    {
        $removed = ['emailActions' => 0, 'workflows' => 0];
        $workflows = [];
        foreach ($doc['workflows'] ?? [] as $wf) {
            // Fixed recipients would leak the author's (or their customers') addresses to every installer.
            $actions = array_values(array_filter($wf['actions'], function ($a) use (&$removed) {
                if ($a['type'] === 'email.send' && ! str_starts_with((string) ($a['to'] ?? ''), 'field:')) {
                    $removed['emailActions']++;

                    return false;
                }

                return true;
            }));
            if ($actions === []) {
                $removed['workflows']++;

                continue;
            }
            $wf['actions'] = $actions;
            $wf['enabled'] = false; // installers review and enable workflows themselves
            $workflows[] = $wf;
        }
        $doc['workflows'] = $workflows;
        $doc['projectId'] = 'template';

        return [$doc, $removed];
    }

    public function submit(Project $project, User $user, array $meta): TemplateSubmission
    {
        if (TemplateSubmission::query()->where('project_id', $project->id)->where('status', 'pending')->exists()) {
            throw ApiException::conflict('submission_pending', 'มีคำขอเผยแพร่เทมเพลตจากโปรเจกต์นี้ที่รอตรวจอยู่แล้ว');
        }
        $def = AppDefinition::query()->where('project_id', $project->id)->firstOrFail();
        [$doc, $removed] = self::sanitize(Upcaster::upcast(DocumentCodec::decode($def->document)));
        $issues = DefinitionValidator::validate($doc);
        if ($issues !== []) {
            throw ApiException::unprocessable('template_invalid', 'เอกสารไม่ผ่านการตรวจสอบ: '.$issues[0]['message']);
        }
        // An existing listing from the same organization + project gets a new version.
        $template = Template::query()->where('source', 'community')->where('source_project_id', $project->id)->where('author_org_id', $project->organization_id)->first();

        return DB::transaction(function () use ($project, $user, $meta, $doc, $removed, $template) {
            $submission = TemplateSubmission::create([
                'template_id' => $template?->id, 'organization_id' => $project->organization_id, 'project_id' => $project->id, 'submitted_by' => $user->id,
                'name' => $meta['name'], 'description' => $meta['description'], 'category' => $meta['category'], 'license' => $meta['license'],
                'changelog' => $meta['changelog'] ?? null, 'document' => DocumentCodec::encode($doc), 'assets' => [], 'sanitization' => $removed, 'status' => 'pending',
            ]);
            $submission->forceFill(['assets' => $this->snapshotAssets($project, $doc['assets'], "templates/submissions/{$submission->id}")])->save();
            $this->audit->record('marketplace.submitted', $project, ['submission_id' => $submission->id, 'template_id' => $template?->id]);

            return $submission;
        });
    }

    /** Copies referenced images out of the tenant prefix so the listing does not depend on the author's workspace. */
    private function snapshotAssets(Project $project, array $ids, string $prefix): array
    {
        $out = [];
        $total = 0;
        foreach (Asset::query()->where('workspace_id', $project->workspace_id)->whereIn('id', $ids)->get() as $a) {
            if (! $a->isServable() || ! Storage::disk($a->disk)->exists($a->path)) {
                throw ApiException::unprocessable('asset_unavailable', "รูป {$a->original_name} ใช้ไม่ได้ กรุณาเปลี่ยนรูปก่อนส่งเทมเพลต");
            }
            $total += $a->size_bytes;
            if ($total > self::MAX_ASSET_BYTES) {
                throw ApiException::unprocessable('template_too_large', 'รูปภาพในเทมเพลตรวมกันต้องไม่เกิน 20 MB');
            }
            $path = $prefix.'/'.$a->id.'.'.pathinfo($a->path, PATHINFO_EXTENSION);
            Storage::disk('assets')->put($path, Storage::disk($a->disk)->get($a->path));
            $out[] = ['ref' => $a->id, 'path' => $path, 'mime' => $a->mime, 'size' => $a->size_bytes, 'width' => $a->width, 'height' => $a->height, 'name' => $a->original_name, 'sha256' => $a->sha256];
        }

        return $out;
    }

    public function approve(TemplateSubmission $s, User $admin, string $reason): Template
    {
        if ($s->status !== 'pending') {
            throw ApiException::conflict('already_reviewed', 'คำขอนี้ได้รับการพิจารณาแล้ว');
        }
        $org = Organization::query()->find($s->organization_id);
        $template = DB::transaction(function () use ($s, $admin, $reason, $org) {
            $template = $s->template_id ? Template::query()->lockForUpdate()->find($s->template_id) : null;
            if ($template === null) {
                $slug = 'c-'.trim(substr(Str::slug(Str::ascii($s->name)), 0, 40), '-');
                if ($slug === 'c-' || Template::query()->where('slug', $slug)->exists()) {
                    $slug = 'c-'.strtolower(Str::random(10));
                }
                $template = Template::create([
                    'slug' => $slug, 'name' => $s->name, 'description' => $s->description, 'category' => $s->category,
                    'source' => 'community', 'author_org_id' => $s->organization_id, 'source_project_id' => $s->project_id, 'sort_order' => 1000,
                ]);
            }
            $versionNo = (int) TemplateVersion::query()->where('template_id', $template->id)->max('version') + 1;
            $assets = array_map(function ($a) use ($template, $versionNo) {
                $path = "templates/{$template->id}/v{$versionNo}/".basename($a['path']);
                Storage::disk('assets')->copy($a['path'], $path);

                return ['path' => $path] + $a;
            }, $s->assets);
            $version = TemplateVersion::create([
                'template_id' => $template->id, 'version' => $versionNo, 'document' => $s->document, 'content_hash' => DocumentCodec::hash($s->document),
                'assets' => $assets, 'changelog' => $s->changelog,
            ]);
            $template->fill([
                'name' => $s->name, 'description' => $s->description, 'category' => $s->category, 'license' => $s->license, 'author_name' => $org?->name,
                'current_version_id' => $version->id, 'is_published' => true, 'delisted_reason' => null,
            ])->save();
            $s->forceFill(['template_id' => $template->id, 'status' => 'approved', 'reviewed_by' => $admin->id, 'review_reason' => $reason, 'reviewed_at' => now()])->save();
            Storage::disk('assets')->deleteDirectory("templates/submissions/{$s->id}");

            return $template;
        });
        $this->audit->record('marketplace.approved', $template, ['submission_id' => $s->id, 'reason' => $reason], $s->organization_id, null, $admin->id);
        $this->notify($s, "เทมเพลต \"{$s->name}\" ได้รับการอนุมัติแล้ว", "เทมเพลตของคุณเผยแพร่ในแกลเลอรีแล้ว (เวอร์ชันล่าสุด)\nหมายเหตุจากผู้ตรวจ: {$reason}");

        return $template;
    }

    public function reject(TemplateSubmission $s, User $admin, string $reason): void
    {
        if ($s->status !== 'pending') {
            throw ApiException::conflict('already_reviewed', 'คำขอนี้ได้รับการพิจารณาแล้ว');
        }
        $s->forceFill(['status' => 'rejected', 'reviewed_by' => $admin->id, 'review_reason' => $reason, 'reviewed_at' => now()])->save();
        Storage::disk('assets')->deleteDirectory("templates/submissions/{$s->id}");
        $this->audit->record('marketplace.rejected', $s, ['reason' => $reason], $s->organization_id, null, $admin->id);
        $this->notify($s, "เทมเพลต \"{$s->name}\" ยังไม่ผ่านการตรวจ", "เหตุผล: {$reason}\nแก้ไขโปรเจกต์แล้วส่งใหม่ได้");
    }

    public function delist(Template $t, string $reason, ?User $by): void
    {
        $t->forceFill(['is_published' => false, 'delisted_reason' => $reason])->save();
        $this->audit->record('marketplace.delisted', $t, ['reason' => $reason], $t->author_org_id, null, $by?->id);
    }

    /**
     * Installs a template's files into the target workspace and returns the document with asset
     * refs rewritten. Call inside the project-creation transaction (holds the org lock).
     */
    public function materialize(TemplateVersion $version, Workspace $workspace, User $user, array $doc): array
    {
        $assets = $version->assets ?? [];
        if ($assets === []) {
            return $doc;
        }
        $org = Organization::query()->with('plan')->findOrFail($workspace->organization_id);
        $this->entitlements->assertStorageAvailable($org, array_sum(array_column($assets, 'size')));
        $map = [];
        foreach ($assets as $a) {
            $newId = (string) Str::ulid();
            $ext = pathinfo($a['path'], PATHINFO_EXTENSION);
            $path = "tenants/{$org->id}/{$workspace->id}/assets/{$newId}.{$ext}";
            Storage::disk('assets')->copy($a['path'], $path);
            $asset = new Asset([
                'organization_id' => $org->id, 'workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'disk' => 'assets', 'path' => $path,
                'original_name' => $a['name'], 'mime' => $a['mime'], 'size_bytes' => $a['size'], 'sha256' => $a['sha256'], 'width' => $a['width'], 'height' => $a['height'],
                'scan_status' => 'clean', // scanned when the author uploaded; re-encoded image
            ]);
            $asset->id = $newId;
            $asset->save();
            $map['asset:'.$a['ref']] = 'asset:'.$newId;
        }
        $doc = json_decode(strtr(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $map), true);
        $doc['assets'] = SemanticValidator::collectAssetRefs($doc['components']);

        return $doc;
    }

    private function notify(TemplateSubmission $s, string $subject, string $body): void
    {
        $to = User::query()->whereKey($s->submitted_by)->value('email');
        if ($to) {
            SendBillingEmail::dispatch($to, $subject, $body)->afterCommit();
        }
    }
}
