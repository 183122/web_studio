<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\SupportGrant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Organization side of support access, plus the public abuse report form. */
class SupportGrantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function present(SupportGrant $g): array
    {
        $status = $g->status === 'approved' && $g->expires_at?->isPast() ? 'expired' : $g->status;

        return [
            'id' => $g->id, 'organizationId' => $g->organization_id, 'requestedBy' => User::query()->whereKey($g->requested_by)->value('name'), 'reason' => $g->reason,
            'minutes' => $g->minutes, 'status' => $status, 'decidedAt' => $g->decided_at?->toIso8601String(), 'expiresAt' => $g->expires_at?->toIso8601String(),
            'createdAt' => $g->created_at?->toIso8601String(),
        ];
    }

    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('admin', $organization);

        return response()->json(['data' => SupportGrant::query()->where('organization_id', $organization->id)->latest()->limit(20)->get()->map(fn ($g) => self::present($g))]);
    }

    public function decide(Request $request, Organization $organization, string $id): JsonResponse
    {
        $this->authorize('admin', $organization);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'deny', 'revoke'])]]);
        $g = SupportGrant::query()->where('organization_id', $organization->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบคำขอ'));
        if ($d['decision'] === 'revoke') {
            if ($g->status !== 'approved') {
                throw ApiException::conflict('not_active', 'คำขอนี้ไม่ได้อยู่ในสถานะอนุมัติ');
            }
            $g->fill(['status' => 'revoked', 'expires_at' => now()])->save();
        } else {
            if ($g->status !== 'pending') {
                throw ApiException::conflict('already_decided', 'คำขอนี้ได้รับการพิจารณาแล้ว');
            }
            $approve = $d['decision'] === 'approve';
            $g->fill(['status' => $approve ? 'approved' : 'denied', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'expires_at' => $approve ? now()->addMinutes($g->minutes) : null])->save();
        }
        $this->audit->record('support.access_'.['approve' => 'approved', 'deny' => 'denied', 'revoke' => 'revoked'][$d['decision']], $organization, ['grant_id' => $g->id], $organization->id);

        return response()->json(['data' => self::present($g)]);
    }

    /** Public, rate-limited abuse report for published sites. */
    public function reportAbuse(Request $request): JsonResponse
    {
        $d = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'category' => ['required', Rule::in(['phishing', 'malware', 'spam', 'illegal', 'other'])],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        $host = strtolower((string) parse_url($d['url'], PHP_URL_HOST));
        $orgId = Domain::query()->where('active_hostname', $host)->value('organization_id');
        AbuseReport::create([
            'url' => $d['url'], 'host' => $host, 'organization_id' => $orgId, 'category' => $d['category'], 'details' => $d['details'],
            'reporter_email' => $d['email'] ?? null, 'reporter_ip_hash' => hash('sha256', $request->ip().config('app.key')),
        ]);

        return response()->json(['data' => ['received' => true]], 201);
    }
}
