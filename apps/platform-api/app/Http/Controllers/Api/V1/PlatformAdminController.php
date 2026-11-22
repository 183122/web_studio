<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendBillingEmail;
use App\Models\AbuseReport;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Services\UsageMeter;
use App\Support\ApiException;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform operator console (middleware: auth + EnsurePlatformAdmin). Every change requires a reason and is audited. */
class PlatformAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly EntitlementService $entitlements) {}

    private static function summary(Organization $o): array
    {
        return [
            'id' => $o->id, 'name' => $o->name, 'slug' => $o->slug, 'status' => $o->status, 'suspendedReason' => $o->suspended_reason,
            'plan' => $o->plan?->code, 'owner' => User::query()->whereKey($o->owner_id)->value('email'),
            'members' => OrganizationMember::query()->where('organization_id', $o->id)->count(),
            'projects' => Project::query()->where('organization_id', $o->id)->count(),
            'createdAt' => $o->created_at?->toIso8601String(),
        ];
    }

    public function organizations(Request $request): JsonResponse
    {
        $q = Organization::query()->with('plan')->latest();
        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($w) use ($search) {
                Like::contains($w, 'name', $search);
                $w->orWhereIn('owner_id', User::query()->where('email', 'like', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%')->select('id'));
            });
        }
        if ($request->query('status') === 'suspended') {
            $q->where('status', 'suspended');
        }
        $page = $q->paginate(25);

        return response()->json(['data' => collect($page->items())->map(fn ($o) => self::summary($o)), 'meta' => ['total' => $page->total(), 'lastPage' => $page->lastPage()]]);
    }

    public function organization(Organization $organization): JsonResponse
    {
        $sub = Subscription::query()->where('organization_id', $organization->id)->first();

        return response()->json(['data' => self::summary($organization->load('plan')) + [
            'usage' => $this->entitlements->usage($organization),
            'subscription' => $sub ? ['status' => $sub->status, 'plan' => Plan::query()->whereKey($sub->plan_id)->value('code'), 'currentPeriodEnd' => $sub->current_period_end?->toIso8601String()] : null,
            'projectsList' => Project::query()->where('organization_id', $organization->id)->latest('updated_at')->limit(50)->get(['id', 'name', 'status', 'updated_at'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status]),
            'domains' => Domain::query()->where('organization_id', $organization->id)->whereNull('released_at')->pluck('hostname'),
            'supportGrants' => SupportGrant::query()->where('organization_id', $organization->id)->latest()->limit(10)->get()->map(fn ($g) => SupportGrantController::present($g)),
            'recentAudit' => AuditLog::query()->where('organization_id', $organization->id)->latest('id')->limit(20)->get(['action', 'created_at'])
                ->map(fn ($a) => ['action' => $a->action, 'at' => $a->created_at?->toIso8601String()]),
        ]]);
    }

    public function setPlan(Request $request, Organization $organization): JsonResponse
    {
        $d = $request->validate(['plan' => ['required', Rule::exists('plans', 'code')], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $from = $organization->plan?->code;
        $organization->forceFill(['plan_id' => Plan::query()->where('code', $d['plan'])->value('id')])->save();
        $this->audit->record('admin.plan_changed', $organization, ['from' => $from, 'to' => $d['plan'], 'reason' => $d['reason']], $organization->id);

        return $this->organization($organization->fresh());
    }

    public function grantCredits(Request $request, Organization $organization, UsageMeter $meter): JsonResponse
    {
        $d = $request->validate(['credits' => ['required', 'integer', 'min:1', 'max:100000'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $meter->add($organization->id, 'ai_credit_bonus', $d['credits']);
        $this->audit->record('admin.ai_credits_granted', $organization, ['credits' => $d['credits'], 'reason' => $d['reason'], 'period' => UsageMeter::period()], $organization->id);

        return $this->organization($organization);
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $d = $request->validate(['suspend' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $organization->forceFill($d['suspend']
            ? ['status' => 'suspended', 'suspended_reason' => $d['reason'], 'suspended_at' => now()]
            : ['status' => 'active', 'suspended_reason' => null, 'suspended_at' => null])->save();
        $this->audit->record($d['suspend'] ? 'admin.organization_suspended' : 'admin.organization_reinstated', $organization, ['reason' => $d['reason']], $organization->id);

        return $this->organization($organization);
    }

    public function requestSupport(Request $request, Organization $organization): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'minutes' => ['required', 'integer', Rule::in([30, 60, 120, 240])]]);
        if (SupportGrant::query()->where('organization_id', $organization->id)->where('requested_by', $request->user()->id)->where('status', 'pending')->exists()) {
            throw ApiException::conflict('request_pending', 'มีคำขอที่รอการอนุมัติอยู่แล้ว');
        }
        $g = SupportGrant::create(['organization_id' => $organization->id, 'requested_by' => $request->user()->id, 'reason' => $d['reason'], 'minutes' => $d['minutes'], 'status' => 'pending']);
        $this->audit->record('support.access_requested', $organization, ['grant_id' => $g->id, 'minutes' => $d['minutes'], 'reason' => $d['reason']], $organization->id);
        $owner = User::query()->whereKey($organization->owner_id)->value('email');
        if ($owner) {
            SendBillingEmail::dispatch($owner, 'ฝ่ายสนับสนุนขอสิทธิ์เข้าดูข้อมูลขององค์กร', "ฝ่ายสนับสนุนขอเข้าดูข้อมูลแบบอ่านอย่างเดียว {$d['minutes']} นาที เหตุผล: {$d['reason']}\nอนุมัติหรือปฏิเสธได้ที่หน้า \"ทีมและความปลอดภัย\" — หากไม่อนุมัติ ฝ่ายสนับสนุนจะเข้าถึงไม่ได้");
        }

        return response()->json(['data' => SupportGrantController::present($g)], 201);
    }

    public function abuseReports(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        return response()->json(['data' => AbuseReport::query()->where('status', $status)->latest()->limit(100)->get()->map(fn (AbuseReport $r) => [
            'id' => $r->id, 'url' => $r->url, 'host' => $r->host, 'organizationId' => $r->organization_id, 'category' => $r->category,
            'details' => $r->details, 'status' => $r->status, 'resolution' => $r->resolution, 'createdAt' => $r->created_at?->toIso8601String(),
            'organization' => $r->organization_id ? Organization::query()->whereKey($r->organization_id)->value('name') : null,
        ])]);
    }

    public function resolveAbuse(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(['actioned', 'dismissed'])], 'resolution' => ['required', 'string', 'min:3', 'max:500']]);
        $r = AbuseReport::query()->findOrFail($id);
        $r->fill(['status' => $d['status'], 'resolution' => $d['resolution'], 'handled_by' => $request->user()->id])->save();
        $this->audit->record('admin.abuse_report_resolved', $r, $d, $r->organization_id);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }
}
