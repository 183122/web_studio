<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Publishing\AppUserAuth;
use App\Domain\Publishing\DomainService;
use App\Domain\Publishing\PublishService;
use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Http\Controllers\RuntimeController;
use App\Models\AppDefinition;
use App\Models\AppUser;
use App\Models\Asset;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\PreviewLink;
use App\Models\Project;
use App\Models\Release;
use App\Services\AssetService;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublishingController extends Controller
{
    public function __construct(private readonly PublishService $publisher, private readonly DomainService $domains) {}

    public static function presentDeployment(Deployment $d): array
    {
        return [
            'id' => $d->id, 'action' => $d->action, 'status' => $d->status, 'releaseId' => $d->release_id,
            'sourceRevision' => $d->source_revision, 'steps' => $d->steps, 'error' => $d->error_code ? ['code' => $d->error_code, 'message' => $d->error_message] : null,
            'createdAt' => $d->created_at?->toIso8601String(), 'finishedAt' => $d->finished_at?->toIso8601String(),
        ];
    }

    public static function presentDomain(Domain $d): array
    {
        return [
            'id' => $d->id, 'hostname' => $d->hostname, 'type' => $d->type, 'status' => $d->status,
            'url' => 'https://'.$d->hostname, 'checks' => $d->checks, 'lastCheckedAt' => $d->last_checked_at?->toIso8601String(),
            'instructions' => $d->type === 'custom' ? DomainService::instructions($d) : null,
        ];
    }

    public function overview(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $sub = $this->domains->subdomainOf($project);
        $head = AppDefinition::query()->where('project_id', $project->id)->value('current_revision');
        $live = $project->live_release_id ? Release::query()->find($project->live_release_id) : null;

        return response()->json(['data' => [
            'baseDomain' => config('studio.publishing.base_domain'),
            'subdomain' => $sub ? self::presentDomain($sub) : null,
            'live' => $live ? ['releaseId' => $live->id, 'version' => $live->version, 'sourceRevision' => $live->source_revision, 'publishedAt' => $project->published_at?->toIso8601String()] : null,
            'headRevision' => $head,
            'hasUnpublishedChanges' => $live === null || $live->source_revision !== $head,
            'requiresVerifiedEmail' => (bool) config('studio.publishing.require_verified_email'),
            'releases' => Release::query()->where('project_id', $project->id)->orderByDesc('version')->limit(20)->get()
                ->map(fn (Release $r) => ['id' => $r->id, 'version' => $r->version, 'sourceRevision' => $r->source_revision, 'status' => $r->status,
                    'warnings' => $r->manifest['warnings'] ?? [], 'isLive' => $r->id === $project->live_release_id, 'createdAt' => $r->created_at?->toIso8601String()]),
            'deployments' => Deployment::query()->where('project_id', $project->id)->orderByDesc('created_at')->limit(10)->get()->map(fn ($d) => self::presentDeployment($d)),
            'domains' => Domain::query()->where('project_id', $project->id)->where('type', 'custom')->whereNull('released_at')->get()->map(fn ($d) => self::presentDomain($d)),
        ]]);
    }

    public function setSubdomain(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['slug' => ['required', 'string', 'max:40']]);

        return response()->json(['data' => self::presentDomain($this->domains->reserveSubdomain($project, $data['slug']))]);
    }

    public function publish(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        return response()->json(['data' => self::presentDeployment($this->publisher->requestPublish($project, $request->user())->fresh())], 202);
    }

    public function deployment(Project $project, string $id): JsonResponse
    {
        $this->authorize('view', $project);
        $d = Deployment::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบการเผยแพร่'));

        return response()->json(['data' => self::presentDeployment($d)]);
    }

    public function rollback(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['releaseId' => ['required', 'string', 'size:26']]);
        $release = Release::query()->where('project_id', $project->id)->whereKey($data['releaseId'])->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบ release'));

        return response()->json(['data' => self::presentDeployment($this->publisher->rollback($project, $release, $request->user()))]);
    }

    public function unpublish(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        return response()->json(['data' => self::presentDeployment($this->publisher->unpublish($project, $request->user()))]);
    }

    // ---------------------------------------------------------------- custom domains (M28)

    public function addDomain(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253']]);

        return response()->json(['data' => self::presentDomain($this->domains->addCustom($project, $data['hostname']))], 201);
    }

    private function findDomain(Request $request, string $id): array
    {
        $domain = Domain::query()->whereKey($id)->whereNull('released_at')->first();
        $project = $domain?->project_id ? Project::query()->find($domain->project_id) : null;
        if ($project === null) {
            throw new ApiException(404, 'not_found', 'ไม่พบโดเมน');
        }

        return [$domain, $project];
    }

    public function domainStatus(Request $request, string $id): JsonResponse
    {
        [$domain, $project] = $this->findDomain($request, $id);
        $this->authorize('view', $project);

        return response()->json(['data' => self::presentDomain($domain)]);
    }

    public function verifyDomain(Request $request, string $id): JsonResponse
    {
        [$domain, $project] = $this->findDomain($request, $id);
        $this->authorize('manage', $project);

        return response()->json(['data' => self::presentDomain($this->domains->check($domain))]);
    }

    public function removeDomain(Request $request, string $id): JsonResponse
    {
        [$domain, $project] = $this->findDomain($request, $id);
        $this->authorize('manage', $project);
        if ($domain->type !== 'custom') {
            throw ApiException::unprocessable('cannot_remove_subdomain', 'เปลี่ยนชื่อโดเมนย่อยแทนการลบ');
        }
        $this->domains->release($domain);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Caddy on-demand TLS "ask" hook. */
    public function tlsAsk(Request $request): JsonResponse
    {
        return $this->domains->mayIssueCertificate((string) $request->query('domain', ''))
            ? response()->json(['allowed' => true])
            : response()->json(['allowed' => false], 404);
    }

    // ---------------------------------------------------------------- preview links (M26)

    public function createPreviewLink(Request $request, Project $project, AuditLogger $audit): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:'.config('studio.publishing.preview_link_max_days')]]);
        $head = AppDefinition::query()->where('project_id', $project->id)->firstOrFail();
        $token = Str::random(40);
        $link = PreviewLink::create([
            'project_id' => $project->id, 'token_hash' => hash('sha256', $token), 'revision' => $head->current_revision,
            'document' => $head->document, 'expires_at' => now()->addDays($data['days']), 'created_by' => $request->user()->id,
        ]);
        $audit->record('preview_link.created', $project, ['link_id' => $link->id, 'days' => $data['days']]);

        return response()->json(['data' => self::presentLink($link) + ['url' => config('studio.frontend_url').'/share/'.$token, 'path' => '/share/'.$token]], 201);
    }

    private static function presentLink(PreviewLink $l): array
    {
        return ['id' => $l->id, 'revision' => $l->revision, 'expiresAt' => $l->expires_at?->toIso8601String(), 'revokedAt' => $l->revoked_at?->toIso8601String(), 'createdAt' => $l->created_at?->toIso8601String()];
    }

    public function previewLinks(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => PreviewLink::query()->where('project_id', $project->id)->orderByDesc('created_at')->get()->map(fn ($l) => self::presentLink($l))]);
    }

    public function revokePreviewLink(Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        PreviewLink::query()->where('project_id', $project->id)->whereKey($id)->update(['revoked_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Public: read-only snapshot for reviewers. Expired/revoked/unknown links are indistinguishable (404). */
    public function showPreviewLink(string $token, AssetService $assetService): JsonResponse
    {
        $link = strlen($token) === 40 ? PreviewLink::query()->where('token_hash', hash('sha256', $token))->first() : null;
        if ($link === null || $link->revoked_at !== null || $link->expires_at->isPast()) {
            throw new ApiException(404, 'not_found', 'ลิงก์นี้หมดอายุหรือไม่มีอยู่');
        }
        $project = Project::query()->find($link->project_id);
        $doc = RuntimeController::publicDocument(DocumentCodec::decode($link->document));
        // Short-lived signed URLs for exactly the images this snapshot references.
        $assets = Asset::query()->where('workspace_id', $project?->workspace_id)->whereIn('id', $doc['assets'])->get()
            ->filter(fn (Asset $a) => $a->isServable())
            ->mapWithKeys(fn (Asset $a) => ['asset:'.$a->id => $assetService->signedUrl($a)]);

        return response()->json(['data' => [
            'projectName' => $project?->name, 'revision' => $link->revision, 'expiresAt' => $link->expires_at->toIso8601String(),
            'document' => DocumentCodec::restoreObjects($doc), 'assetUrls' => (object) $assets->all(),
        ]])->header('X-Robots-Tag', 'noindex');
    }

    // ---------------------------------------------------------------- app users (generated-app accounts)

    public function appUsers(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => AppUser::query()->where('project_id', $project->id)->orderBy('created_at')->get()->map(fn (AppUser $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'status' => $u->status,
            'lastLoginAt' => $u->last_login_at?->toIso8601String(), 'createdAt' => $u->created_at?->toIso8601String(),
        ])]);
    }

    public function updateAppUser(Request $request, Project $project, string $id, AppUserAuth $auth, AuditLogger $audit): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['role' => ['sometimes', Rule::in(['member', 'admin'])], 'status' => ['sometimes', Rule::in(['active', 'disabled'])]]);
        $user = AppUser::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบผู้ใช้'));
        $user->update($data);
        if (($data['status'] ?? null) === 'disabled' || isset($data['role'])) {
            $auth->revokeAll($user); // role/status changes take effect immediately
        }
        $audit->record('app_user.updated', $project, ['app_user_id' => $user->id] + $data);

        return response()->json(['data' => ['id' => $user->id, 'role' => $user->role, 'status' => $user->status]]);
    }
}
