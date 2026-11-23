<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceService;
use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateSubmission;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Community templates: submission (authors), moderation (platform admins), reporting (anyone signed in). */
class MarketplaceController extends Controller
{
    public function __construct(private readonly MarketplaceService $market) {}

    private static function presentSubmission(TemplateSubmission $s, bool $withDocument = false): array
    {
        return [
            'id' => $s->id, 'templateId' => $s->template_id, 'name' => $s->name, 'description' => $s->description, 'category' => $s->category,
            'license' => $s->license, 'changelog' => $s->changelog, 'status' => $s->status, 'reviewReason' => $s->review_reason,
            'sanitization' => $s->sanitization, 'assetCount' => count($s->assets ?? []),
            'organization' => Organization::query()->whereKey($s->organization_id)->value('name'),
            'submittedBy' => User::query()->whereKey($s->submitted_by)->value('name'),
            'createdAt' => $s->created_at?->toIso8601String(), 'reviewedAt' => $s->reviewed_at?->toIso8601String(),
        ] + ($withDocument ? ['document' => DocumentCodec::decodeForResponse($s->document)] : []);
    }

    public function submit(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $d = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'description' => ['required', 'string', 'min:10', 'max:500'],
            'category' => ['required', Rule::in(MarketplaceService::CATEGORIES)],
            'license' => ['required', Rule::in(MarketplaceService::LICENSES)],
            'changelog' => ['nullable', 'string', 'max:500'],
            'confirmRights' => ['accepted'],
        ]);

        return response()->json(['data' => self::presentSubmission($this->market->submit($project, $request->user(), $d))], 201);
    }

    /** Author view: the organization's submissions and listings. */
    public function mine(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return response()->json(['data' => [
            'submissions' => TemplateSubmission::query()->where('organization_id', $organization->id)->latest()->limit(50)->get()->map(fn ($s) => self::presentSubmission($s)),
            'templates' => Template::query()->where('author_org_id', $organization->id)->with('currentVersion:id,version')->get()->map(fn ($t) => [
                'id' => $t->id, 'slug' => $t->slug, 'name' => $t->name, 'published' => $t->is_published, 'delistedReason' => $t->delisted_reason,
                'version' => $t->currentVersion?->version, 'installCount' => $t->install_count, 'license' => $t->license,
            ]),
        ]]);
    }

    public function delistOwn(Request $request, Organization $organization, string $id): JsonResponse
    {
        $this->authorize('admin', $organization);
        $t = Template::query()->where('author_org_id', $organization->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบเทมเพลต'));
        $this->market->delist($t, 'ผู้เขียนนำออกจากแกลเลอรี', $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    public function report(Request $request, string $slug): JsonResponse
    {
        $t = Template::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();
        $d = $request->validate(['category' => ['required', Rule::in(['phishing', 'malware', 'spam', 'illegal', 'other'])], 'details' => ['required', 'string', 'min:10', 'max:2000']]);
        AbuseReport::create([
            'url' => 'template:'.$t->slug, 'host' => null, 'organization_id' => $t->author_org_id, 'category' => $d['category'], 'details' => $d['details'],
            'reporter_email' => $request->user()->email, 'reporter_ip_hash' => hash('sha256', $request->ip().config('app.key')),
        ]);

        return response()->json(['data' => ['received' => true]], 201);
    }

    // ---------------------------------------------------------------- moderation (platform admins)

    public function queue(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true) ? $request->query('status') : 'pending';

        return response()->json(['data' => TemplateSubmission::query()->where('status', $status)->oldest()->limit(50)->get()->map(fn ($s) => self::presentSubmission($s))]);
    }

    public function submission(string $id): JsonResponse
    {
        return response()->json(['data' => self::presentSubmission(TemplateSubmission::query()->findOrFail($id), true)]);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $s = TemplateSubmission::query()->findOrFail($id);
        if ($d['decision'] === 'approve') {
            $t = $this->market->approve($s, $request->user(), $d['reason']);

            return response()->json(['data' => self::presentSubmission($s->fresh()) + ['templateSlug' => $t->slug]]);
        }
        $this->market->reject($s, $request->user(), $d['reason']);

        return response()->json(['data' => self::presentSubmission($s->fresh())]);
    }

    public function delist(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $t = Template::query()->where('source', 'community')->findOrFail($id);
        $this->market->delist($t, $d['reason'], $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }
}
