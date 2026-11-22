<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** PDPA data-subject rights for platform users: access (export), consent, erasure. */
class PrivacyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function consent(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => [
            'termsVersion' => $u->terms_version, 'privacyVersion' => $u->privacy_version, 'consentedAt' => $u->consented_at?->toIso8601String(),
            'currentTermsVersion' => config('studio.legal.terms_version'), 'currentPrivacyVersion' => config('studio.legal.privacy_version'),
            'marketing' => $u->marketing_consent_at !== null,
        ]]);
    }

    public function updateConsent(Request $request): JsonResponse
    {
        $d = $request->validate(['marketing' => ['sometimes', 'boolean'], 'acceptCurrent' => ['sometimes', 'accepted']]);
        $u = $request->user();
        if (array_key_exists('marketing', $d)) {
            $u->marketing_consent_at = $d['marketing'] ? now() : null;
        }
        if (! empty($d['acceptCurrent'])) {
            $u->forceFill(['terms_version' => config('studio.legal.terms_version'), 'privacy_version' => config('studio.legal.privacy_version'), 'consented_at' => now()]);
        }
        $u->save();
        $this->audit->record('privacy.consent_updated', $u, ['marketing' => $u->marketing_consent_at !== null, 'terms_version' => $u->terms_version], null, null, $u->id);

        return $this->consent($request);
    }

    /** Machine-readable copy of the personal data held about the signed-in user. */
    public function export(Request $request): Response
    {
        $u = $request->user();
        $data = [
            'generatedAt' => now()->toIso8601String(),
            'profile' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'locale' => $u->locale, 'emailVerifiedAt' => $u->email_verified_at?->toIso8601String(), 'createdAt' => $u->created_at?->toIso8601String(), 'lastLoginAt' => $u->last_login_at?->toIso8601String(), 'twoFactorEnabled' => $u->two_factor_confirmed_at !== null],
            'consent' => ['termsVersion' => $u->terms_version, 'privacyVersion' => $u->privacy_version, 'consentedAt' => $u->consented_at?->toIso8601String(), 'marketingConsentAt' => $u->marketing_consent_at?->toIso8601String()],
            'organizations' => OrganizationMember::query()->where('user_id', $u->id)->get()->map(fn ($m) => ['organization' => Organization::query()->whereKey($m->organization_id)->value('name'), 'role' => $m->role]),
            'workspaces' => WorkspaceMember::query()->where('user_id', $u->id)->get()->map(fn ($m) => ['workspace' => Workspace::query()->whereKey($m->workspace_id)->value('name'), 'role' => $m->role]),
            'sessions' => config('session.driver') === 'database' ? DB::table(config('session.table', 'sessions'))->where('user_id', $u->id)->get(['ip_address', 'user_agent', 'last_activity'])->map(fn ($s) => ['ip' => $s->ip_address, 'userAgent' => $s->user_agent, 'lastActivity' => date(DATE_ATOM, (int) $s->last_activity)]) : [],
            'aiRequests' => DB::table('ai_requests')->where('user_id', $u->id)->orderByDesc('created_at')->limit(1000)->get(['kind', 'prompt_redacted', 'status', 'created_at']),
            'activity' => AuditLog::query()->where('actor_id', $u->id)->orderByDesc('id')->limit(5000)->get(['action', 'ip_address', 'user_agent', 'created_at'])->map(fn ($a) => ['action' => $a->action, 'ip' => $a->ip_address, 'userAgent' => $a->user_agent, 'at' => $a->created_at?->toIso8601String()]),
            'note' => 'ข้อมูลในแอปที่คุณสร้าง (เช่น ข้อมูลผู้สมัครในฟอร์ม) เป็นข้อมูลของโปรเจกต์ ดาวน์โหลดได้จากหน้า "ข้อมูล" ของแต่ละโปรเจกต์ (ส่งออก CSV)',
        ];
        $this->audit->record('privacy.data_exported', $u, [], null, null, $u->id);

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Ymd').'.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Erasure. Organizations owned alone are scheduled for deletion (purged after a grace period);
     * owning an organization with other members requires transferring it first. The account is
     * anonymized immediately; audit records keep only the opaque user id (legal accountability).
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $d = $request->validate(['password' => ['required', 'string'], 'confirm' => ['required', 'in:DELETE']]);
        $u = $request->user();
        if (! Hash::check($d['password'], $u->password)) {
            throw ValidationException::withMessages(['password' => 'รหัสผ่านไม่ถูกต้อง']);
        }
        if ($u->is_platform_admin) {
            throw ApiException::unprocessable('platform_admin', 'ผู้ดูแลแพลตฟอร์มต้องถูกถอนสิทธิ์ก่อนลบบัญชี');
        }
        $owned = Organization::query()->where('owner_id', $u->id)->get();
        foreach ($owned as $org) {
            if (OrganizationMember::query()->where('organization_id', $org->id)->where('user_id', '!=', $u->id)->exists()) {
                throw ApiException::unprocessable('transfer_ownership_first', "องค์กร \"{$org->name}\" มีสมาชิกคนอื่น กรุณาโอนความเป็นเจ้าของหรือนำสมาชิกออกก่อนลบบัญชี");
            }
        }
        DB::transaction(function () use ($u, $owned) {
            foreach ($owned as $org) {
                Subscription::query()->where('organization_id', $org->id)->whereIn('status', ['active', 'past_due', 'trialing'])->update(['status' => 'canceled', 'ended_at' => now()]);
                $org->forceFill(['status' => 'suspended', 'suspended_reason' => 'owner_account_deleted', 'suspended_at' => now()])->save();
                $org->delete(); // soft delete; purged by studio:retention:run after the grace period
                $this->audit->record('privacy.organization_scheduled_for_deletion', $org, [], $org->id, null, $u->id);
            }
            OrganizationMember::query()->where('user_id', $u->id)->delete();
            WorkspaceMember::query()->where('user_id', $u->id)->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $u->id)->delete();
            }
            $this->audit->record('privacy.account_deleted', $u, ['organizations_scheduled' => $owned->count()], null, null, $u->id);
            $u->forceFill([
                'name' => 'ผู้ใช้ที่ลบแล้ว', 'email' => 'deleted-'.strtolower($u->id).'@deleted.invalid', 'password' => Hash::make(Str::random(64)),
                'remember_token' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
                'marketing_consent_at' => null, 'email_verified_at' => null,
            ])->save();
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
